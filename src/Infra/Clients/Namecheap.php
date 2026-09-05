<?php

namespace Talivio\Sdk\Infra\Clients;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use SimpleXMLElement;
use Talivio\Sdk\Infra\Contracts\BulkAvailability;
use Talivio\Sdk\Infra\Contracts\Registrar;
use Talivio\Sdk\Infra\Exceptions\AuthCodeUnavailableException;
use Talivio\Sdk\Infra\Support\AppliesDomainPolicy;
use Talivio\Sdk\Infra\Support\PhoneNumbers;
use Talivio\Sdk\Infra\Support\RetriesTransientFailures;

/**
 * Talivio's own Namecheap reseller account (`talivio.infra.namecheap`) —
 * one account registers domains on behalf of every product's customers.
 *
 * Namecheap's API is a single XML-over-HTTP endpoint: every call POSTs
 * `Command=namecheap.<area>.<verb>` plus the account's global parameters
 * (ApiUser/ApiKey/UserName/ClientIp) and reads an <ApiResponse Status="OK|
 * ERROR"> envelope back. Plain Laravel HTTP client, no SDK.
 *
 * Things Namecheap does differently from Openprovider that shape this class:
 *  - Domain operations are keyed by the DOMAIN NAME, not an id. The
 *    DomainID returned by create is informational; renew/lock/DNS all
 *    take DomainName. transfer.getStatus is the exception (TransferID).
 *  - Prices come from a separate call (users.getPricing, per TLD) — check
 *    only says available/premium — so quotes are cached briefly per TLD.
 *    The REGISTER price is usually a first-year promo far below RENEW;
 *    see registrationPrice() for why the quote is the higher of the two.
 *  - There is no API call that returns a domain's EPP/auth code
 *    (getAuthCode() throws AuthCodeUnavailableException).
 *  - A transfer request can't set nameservers; configureNameservers()
 *    runs once the transfer is seen to complete.
 *  - Nameservers are whatever the Dns zone hands out (Cloudflare assigns
 *    them per zone) — always passed in, never configured here.
 *  - Contacts want first/last name split, a StateProvince, and phones in
 *    EPP "+CC.NUMBER" form (see PhoneNumbers).
 *
 * ⚠️ Every call sends ClientIp, which must be whitelisted on the Namecheap
 * account (Profile → Tools → API Access) — an unlisted IP is rejected as
 * error 1011102/1011150 before anything else is looked at. The API user
 * is the ACCOUNT USERNAME; the key alone is not enough. The sandbox
 * (api.sandbox.namecheap.com) is a SEPARATE account with its own key.
 */
class Namecheap implements BulkAvailability, Registrar
{
    use AppliesDomainPolicy, RetriesTransientFailures;

    public const NAME = 'namecheap';

    public const PRODUCTION_URL = 'https://api.namecheap.com/xml.response';

    public const SANDBOX_URL = 'https://api.sandbox.namecheap.com/xml.response';

    /**
     * How long a TLD's reseller price is cached. Namecheap rate-limits the
     * API (20/min, 700/hour, 8000/day per account) and search-as-you-type
     * would otherwise fetch the .com price on every keystroke; the price
     * sheet itself changes rarely.
     */
    protected const PRICE_CACHE_SECONDS = 6 * 3600;

    /**
     * @param  string|null  $apiUser  usually identical to $username; differs only for sub-accounts
     */
    public function __construct(
        protected string $apiKey,
        protected string $username,
        protected string $clientIp,
        protected ?string $apiUser = null,
        protected bool $sandbox = false,
    ) {}

    /**
     * Null when the environment lacks the three things Namecheap can't be
     * called without — a product then shows "not configured" instead of
     * failing inside a request.
     */
    public static function fromConfig(): ?static
    {
        $cfg = (array) config('talivio.infra.namecheap', []);

        if (blank($cfg['api_key'] ?? null) || blank($cfg['username'] ?? null) || blank($cfg['client_ip'] ?? null)) {
            return null;
        }

        return new static(
            apiKey: (string) $cfg['api_key'],
            username: (string) $cfg['username'],
            clientIp: (string) $cfg['client_ip'],
            apiUser: filled($cfg['api_user'] ?? null) ? (string) $cfg['api_user'] : null,
            sandbox: (bool) ($cfg['sandbox'] ?? false),
        );
    }

    /** @return list<string> */
    public static function requiredEnv(): array
    {
        return ['NAMECHEAP_API_KEY', 'NAMECHEAP_USERNAME', 'NAMECHEAP_CLIENT_IP'];
    }

    public function baseUrl(): string
    {
        return $this->sandbox ? self::SANDBOX_URL : self::PRODUCTION_URL;
    }

    public function isSandbox(): bool
    {
        return $this->sandbox;
    }

    public function checkAvailability(string $domain): array
    {
        [, $extension] = $this->split($domain);

        $this->guardSupportedTld($extension);

        $result = $this->checkBatch([$domain])[strtolower(trim($domain))] ?? null;

        if ($result === null) {
            throw new RuntimeException('Namecheap returned no result for this domain.');
        }

        if ($result['error'] !== null) {
            throw new RuntimeException('Namecheap availability check failed: '.$result['error']);
        }

        // Premium names carry their own (much higher) registration AND
        // renewal price that create/renew have to echo back explicitly.
        // Talivio quotes flat per-TLD pricing (the purchase price is
        // reused for every renewal), so premiums are simply not sold —
        // reported as unavailable rather than quoted at the wrong price.
        if ($result['premium']) {
            Log::info('Namecheap: premium domain reported as unavailable.', ['domain' => $domain]);
        }

        [$price, $currency] = $this->registrationPrice($extension);

        return [
            'available' => $result['available'] && ! $result['premium'],
            'premium' => $result['premium'],
            'price' => $this->withMargin($price),
            'currency' => $currency,
        ];
    }

    public function checkMany(array $domains): array
    {
        $wanted = [];

        foreach ($domains as $domain) {
            $domain = strtolower(trim((string) $domain));

            try {
                [, $extension] = $this->split($domain);
            } catch (RuntimeException) {
                continue;
            }

            if ($this->supportsTld($extension) && ! in_array($domain, $wanted, true)) {
                $wanted[] = $domain;
            }
        }

        if ($wanted === []) {
            return [];
        }

        try {
            $checked = $this->checkBatch($wanted);
        } catch (RuntimeException) {
            /*
             * `domains.check` batches ALL requested domains into ONE call, so
             * ONE ending the account can't check (a ccTLD needing local
             * presence — .ee and .com.tr were confirmed live 2026-08-16 to
             * fail with error 2030280) fails the WHOLE search. Fall back to
             * one call per domain so the rest still return a result.
             */
            $checked = [];

            foreach ($wanted as $domain) {
                try {
                    $checked += $this->checkBatch([$domain]);
                } catch (RuntimeException) {
                    // Left out — surfaced as "no result", not worth failing over.
                }
            }
        }

        $results = [];

        foreach ($wanted as $domain) {
            $result = $checked[$domain] ?? null;

            if ($result === null || $result['error'] !== null) {
                continue;
            }

            [, $extension] = $this->split($domain);

            try {
                [$price, $currency] = $this->registrationPrice($extension);
                $price = $this->withMargin($price);
            } catch (RuntimeException) {
                [$price, $currency] = [null, 'USD'];
            }

            $results[$domain] = [
                'available' => $result['available'] && ! $result['premium'],
                'premium' => $result['premium'],
                'price' => $price,
                'currency' => $currency,
            ];
        }

        return $results;
    }

    /**
     * One `domains.check` round trip for up to ~50 domains.
     *
     * @param  list<string>  $domains
     * @return array<string, array{available: bool, premium: bool, error: string|null}>
     */
    protected function checkBatch(array $domains): array
    {
        $response = $this->call('namecheap.domains.check', ['DomainList' => strtolower(implode(',', $domains))], idempotent: true);

        $results = [];

        foreach ($response->DomainCheckResult as $candidate) {
            $errorNo = (string) $candidate['ErrorNo'];
            $error = ($errorNo !== '' && $errorNo !== '0')
                ? (((string) $candidate['Description']) ?: 'error '.$errorNo)
                : null;

            $results[strtolower((string) $candidate['Domain'])] = [
                'available' => $this->truthy($candidate['Available']),
                'premium' => $this->truthy($candidate['IsPremiumName']),
                'error' => $error,
            ];
        }

        return $results;
    }

    public function register(string $domain, array $registrant, array $nameservers): string
    {
        [, $extension] = $this->split($domain);

        $this->guardSupportedTld($extension);

        $contact = $this->contact($registrant);

        $params = [
            'DomainName' => strtolower($domain),
            'Years' => 1,
            // GDPR-friendly default: the registrant's contact details never
            // appear in public WHOIS/RDAP output. Free on every TLD
            // Namecheap supports it for; silently skipped where it isn't.
            'AddFreeWhoisguard' => 'yes',
            'WGEnabled' => 'yes',
        ];

        // The Dns zone's nameservers when the zone already exists; left
        // out (Namecheap's own defaults) when the zone can only be created
        // after registration — see Registrar::register().
        $nameservers = $this->nameserverList($nameservers, allowEmpty: true);

        if ($nameservers !== []) {
            $params['Nameservers'] = implode(',', $nameservers);
        }

        // Namecheap wants four contact blocks; the registrant is all of them.
        foreach (['Registrant', 'Tech', 'Admin', 'AuxBilling'] as $role) {
            foreach ($contact as $field => $value) {
                $params[$role.$field] = $value;
            }
        }

        // Charge-bearing and not idempotent — never retried on an ambiguous
        // failure (a resend could register and bill twice).
        $response = $this->call('namecheap.domains.create', $params, idempotent: false);

        $result = $response->DomainCreateResult;

        if (! $result || ! $this->truthy($result['Registered'])) {
            throw new RuntimeException('Namecheap did not confirm the registration for '.$domain.'.');
        }

        $domainId = (string) $result['DomainID'];

        if ($domainId === '') {
            throw new RuntimeException('Namecheap did not return a domain id.');
        }

        return $domainId;
    }

    public function renew(string $domain, string $registrarDomainId, int $years = 1): void
    {
        $response = $this->call('namecheap.domains.renew', [
            'DomainName' => strtolower($domain),
            'Years' => max(1, $years),
        ], idempotent: false);

        $result = $response->DomainRenewResult;

        if (! $result || ! $this->truthy($result['Renew'])) {
            throw new RuntimeException('Namecheap did not confirm the renewal for '.$domain.'.');
        }
    }

    public function transferIn(string $domain, string $authCode, array $registrant): string
    {
        [, $extension] = $this->split($domain);

        $this->guardSupportedTld($extension);

        // Namecheap keeps the WHOIS contacts the domain arrives with, so
        // $registrant isn't sent here — the product carries it until the
        // transfer resolves.
        $response = $this->call('namecheap.domains.transfer.create', [
            'DomainName' => strtolower($domain),
            'Years' => 1,
            'EPPCode' => $authCode,
            'AddFreeWhoisguard' => 'yes',
            'WGenable' => 'yes',
        ], idempotent: false);

        $result = $response->DomainTransferCreateResult;

        if (! $result || ! $this->truthy($result['Transfer'])) {
            throw new RuntimeException('Namecheap did not accept the transfer request for '.$domain.'.');
        }

        $transferId = (string) $result['TransferID'];

        if ($transferId === '') {
            throw new RuntimeException('Namecheap did not return a transfer id.');
        }

        return $transferId;
    }

    public function transferStatus(string $registrarDomainId): string
    {
        $response = $this->call('namecheap.domains.transfer.getStatus', [
            'TransferID' => $registrarDomainId,
        ], idempotent: true);

        $result = $response->DomainTransferGetStatusResult;

        if (! $result) {
            throw new RuntimeException('Namecheap returned no status for transfer '.$registrarDomainId.'.');
        }

        // Namecheap doesn't document its numeric StatusIDs; the free-text
        // Status is what its own SDK classifies on ("Transfer completed",
        // "Cancelled — invalid EPP code", "Transfer in progress, awaiting
        // EPP code" ...).
        $status = strtolower((string) $result['Status']);

        return match (true) {
            str_contains($status, 'complet') => 'completed',
            str_contains($status, 'cancel'),
            str_contains($status, 'fail'),
            str_contains($status, 'reject'),
            str_contains($status, 'denied') => 'failed',
            default => 'pending',
        };
    }

    public function configureNameservers(string $domain, string $registrarDomainId, array $nameservers): void
    {
        [$sld, $tld] = $this->split($domain);

        $response = $this->call('namecheap.domains.dns.setCustom', [
            'SLD' => $sld,
            'TLD' => $tld,
            'Nameservers' => implode(',', $this->nameserverList($nameservers)),
        ], idempotent: true);

        $result = $response->DomainDNSSetCustomResult;

        if (! $result || ! $this->truthy($result['Updated'])) {
            throw new RuntimeException('Namecheap did not confirm the nameserver update for '.$domain.'.');
        }
    }

    /**
     * Namecheap has no API call for this — its own SDK covers every
     * documented command and none returns an EPP code; the panel issues it
     * to the registrant's email on request. See AuthCodeUnavailableException.
     */
    public function getAuthCode(string $domain, string $registrarDomainId): string
    {
        throw AuthCodeUnavailableException::forRegistrar(self::NAME);
    }

    public function setTransferLock(string $domain, string $registrarDomainId, bool $locked): void
    {
        $response = $this->call('namecheap.domains.setRegistrarLock', [
            'DomainName' => strtolower($domain),
            'LockAction' => $locked ? 'LOCK' : 'UNLOCK',
        ], idempotent: true);

        $result = $response->DomainSetRegistrarLockResult;

        if (! $result || ! $this->truthy($result['IsSuccess'])) {
            throw new RuntimeException('Namecheap did not confirm the transfer lock change for '.$domain.'.');
        }
    }

    /**
     * The per-TLD price Talivio quotes, in minor units BEFORE its margin,
     * plus the currency (Namecheap bills in USD).
     *
     * Not the first-year registration price: Namecheap's REGISTER price
     * is routinely a promo (.shop $0.98 first year, $48.98 to renew) and
     * the products charge every later renewal at the purchase price —
     * quoting the promo would sell renewals at a loss. So the quote is
     * the HIGHER of the register and renew prices, plus the per-year
     * AdditionalCost Namecheap adds on top of both (the ICANN fee). What
     * the customer sees, pays and renews at is one stable number; the
     * first-year promo is Talivio's margin, not the customer's discount.
     *
     * @return array{0: int, 1: string}
     */
    protected function registrationPrice(string $tld): array
    {
        $tld = strtolower($tld);

        return Cache::remember("namecheap:price:v2:{$tld}", self::PRICE_CACHE_SECONDS, function () use ($tld) {
            // No ActionName: one call returns every category (register,
            // renew, transfer, reactivate) for the TLD.
            $response = $this->call('namecheap.users.getPricing', [
                'ProductType' => 'DOMAIN',
                'ProductCategory' => 'DOMAINS',
                'ProductName' => $tld,
            ], idempotent: true);

            $quotes = [];
            $currency = 'USD';

            foreach ($response->UserGetPricingResult->ProductType as $type) {
                foreach ($type->ProductCategory as $category) {
                    $action = strtolower((string) $category['Name']);

                    if (! in_array($action, ['register', 'renew'], true)) {
                        continue;
                    }

                    foreach ($category->Product as $product) {
                        if (strcasecmp((string) $product['Name'], $tld) !== 0) {
                            continue;
                        }

                        foreach ($product->Price as $price) {
                            if ((string) $price['Duration'] !== '1' || strcasecmp((string) $price['DurationType'], 'YEAR') !== 0) {
                                continue;
                            }

                            $amount = $this->firstPositive($price, ['YourPrice', 'Price', 'RegularPrice']);

                            if ($amount === null) {
                                continue;
                            }

                            $additional = $this->firstPositive($price, ['YourAdditonalCost', 'AdditionalCost', 'RegularAdditionalCost']) ?? 0.0;
                            $quotes[$action] = $amount + $additional;
                            $currency = strtoupper((string) $price['Currency'] ?: $currency);
                        }
                    }
                }
            }

            if ($quotes === []) {
                throw new RuntimeException("Namecheap has no price listed for .{$tld}.");
            }

            return [(int) round(max($quotes) * 100), $currency];
        });
    }

    /**
     * The first of $attributes on a <Price> node with a positive amount —
     * Namecheap leaves some empty ("") or "0.0" depending on the TLD.
     *
     * @param  list<string>  $attributes
     */
    protected function firstPositive(SimpleXMLElement $price, array $attributes): ?float
    {
        foreach ($attributes as $attribute) {
            $amount = (float) ((string) $price[$attribute]);

            if ($amount > 0) {
                return $amount;
            }
        }

        return null;
    }

    /**
     * Maps the platform's registrant shape onto Namecheap's contact fields
     * (sans role prefix — register() fans them out to all four roles).
     *
     * @param  array{name: string, email: string, phone: string, address: string, city: string, postal_code: string, country: string, state?: string}  $registrant
     * @return array<string, string>
     */
    protected function contact(array $registrant): array
    {
        $name = trim((string) ($registrant['name'] ?? ''));
        $parts = preg_split('/\s+/', $name) ?: [];
        $firstName = array_shift($parts) ?? '';
        // A single-word name still needs a non-empty LastName.
        $lastName = $parts === [] ? $firstName : implode(' ', $parts);

        $country = strtoupper(trim((string) ($registrant['country'] ?? '')));

        return [
            'FirstName' => $firstName,
            'LastName' => $lastName,
            'Address1' => (string) ($registrant['address'] ?? ''),
            'City' => (string) ($registrant['city'] ?? ''),
            // Required by Namecheap even where the country has no such
            // subdivision — the city stands in.
            'StateProvince' => (string) (($registrant['state'] ?? '') ?: ($registrant['city'] ?? '')),
            'PostalCode' => (string) ($registrant['postal_code'] ?? ''),
            'Country' => $country,
            'Phone' => PhoneNumbers::toEpp((string) ($registrant['phone'] ?? ''), $country),
            'EmailAddress' => (string) ($registrant['email'] ?? ''),
        ];
    }

    /**
     * One round trip to Namecheap. Returns the <CommandResponse> node of a
     * successful envelope; throws a RuntimeException carrying Namecheap's
     * own error text otherwise. $idempotent controls transport-level
     * retries: reads are retried on connection failures / 5xx, anything
     * that charges the account never is.
     *
     * @param  array<string, mixed>  $params
     */
    protected function call(string $command, array $params, bool $idempotent): SimpleXMLElement
    {
        // Everything goes over the wire as form fields anyway; stringifying
        // up front keeps the recorded request uniform for Http::fake
        // assertions too.
        $payload = array_map(fn ($value) => (string) $value, [
            'ApiUser' => $this->apiUser ?: $this->username,
            'ApiKey' => $this->apiKey,
            'UserName' => $this->username,
            'ClientIp' => $this->clientIp,
            'Command' => $command,
        ] + $params);

        try {
            $response = $this->request($idempotent)->post($this->baseUrl(), $payload);
        } catch (ConnectionException $e) {
            throw new RuntimeException("Namecheap is unreachable ({$command}): ".$e->getMessage(), previous: $e);
        }

        if ($response->failed()) {
            throw new RuntimeException("Namecheap {$command} failed with HTTP {$response->status()}.");
        }

        $xml = $this->parse($response->body(), $command);

        if (strcasecmp((string) $xml['Status'], 'OK') !== 0) {
            $errors = [];

            foreach ($xml->Errors->Error ?? [] as $error) {
                $errors[] = trim((string) $error).' (#'.((string) $error['Number']).')';
            }

            throw new RuntimeException("Namecheap {$command} failed: ".($errors ? implode('; ', $errors) : 'no error detail returned'));
        }

        return $xml->CommandResponse;
    }

    protected function request(bool $idempotent): PendingRequest
    {
        $request = Http::asForm()->timeout(30)->accept('application/xml');

        return $idempotent ? $this->retrying($request) : $request;
    }

    protected function parse(string $body, string $command): SimpleXMLElement
    {
        $previous = libxml_use_internal_errors(true);

        try {
            $xml = simplexml_load_string($body, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if ($xml === false || $xml->getName() !== 'ApiResponse') {
            throw new RuntimeException("Namecheap {$command} returned an unreadable response.");
        }

        return $xml;
    }

    protected function truthy(mixed $attribute): bool
    {
        return in_array(strtolower(trim((string) $attribute)), ['true', 'yes', '1'], true);
    }
}
