<?php

namespace Talivio\Sdk\Infra\Clients;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Talivio\Sdk\Infra\Contracts\BulkAvailability;
use Talivio\Sdk\Infra\Contracts\Registrar;
use Talivio\Sdk\Infra\Support\AppliesDomainPolicy;
use Talivio\Sdk\Infra\Support\RetriesTransientFailures;

/**
 * Talivio's Openprovider reseller account (`talivio.infra.openprovider`),
 * kept as the ALTERNATIVE registrar driver — Namecheap is the default and
 * the account that actually holds Talivio's domains. Plain HTTP client
 * against Openprovider's REST API, no SDK.
 *
 * Openprovider keys every domain operation by its own numeric id, so the
 * $domain argument the contract also passes is unused for those. Auth is
 * a short-lived bearer obtained per call from /auth/login.
 */
class Openprovider implements BulkAvailability, Registrar
{
    use AppliesDomainPolicy, RetriesTransientFailures;

    public const NAME = 'openprovider';

    public function __construct(
        protected string $username,
        protected string $password,
        protected string $baseUrl = 'https://api.openprovider.eu/v1beta',
    ) {}

    public static function fromConfig(): ?static
    {
        $cfg = (array) config('talivio.infra.openprovider', []);

        if (blank($cfg['username'] ?? null) || blank($cfg['password'] ?? null)) {
            return null;
        }

        return new static(
            username: (string) $cfg['username'],
            password: (string) $cfg['password'],
            baseUrl: rtrim((string) ($cfg['base_url'] ?? 'https://api.openprovider.eu/v1beta'), '/'),
        );
    }

    /** @return list<string> */
    public static function requiredEnv(): array
    {
        return ['OPENPROVIDER_USERNAME', 'OPENPROVIDER_PASSWORD'];
    }

    public function checkAvailability(string $domain): array
    {
        [, $extension] = $this->split($domain);

        $this->guardSupportedTld($extension);

        $result = $this->checkMany([$domain])[strtolower(trim($domain))] ?? null;

        if ($result === null) {
            throw new RuntimeException('Openprovider returned no result for this domain.');
        }

        return [
            'available' => $result['available'],
            'premium' => $result['premium'],
            'price' => (int) $result['price'],
            'currency' => $result['currency'],
        ];
    }

    public function checkMany(array $domains): array
    {
        $wanted = [];

        foreach ($domains as $domain) {
            $domain = strtolower(trim((string) $domain));

            try {
                [$name, $extension] = $this->split($domain);
            } catch (RuntimeException) {
                continue;
            }

            if ($this->supportsTld($extension)) {
                $wanted[$domain] = ['domain' => $name, 'extension' => $extension];
            }
        }

        if ($wanted === []) {
            return [];
        }

        $response = $this->post('/domains/check', ['domains' => array_values($wanted)], 'availability check', idempotent: true);

        $results = [];

        foreach ((array) $response->json('data.results', []) as $index => $result) {
            $domain = strtolower((string) ($result['domain'] ?? array_keys($wanted)[$index] ?? ''));

            if ($domain === '' || ! isset($wanted[$domain])) {
                continue;
            }

            $premium = (bool) ($result['is_premium'] ?? false);
            $reseller = $result['price']['reseller'] ?? null;

            $results[$domain] = [
                'available' => ($result['status'] ?? null) === 'free' && ! $premium,
                'premium' => $premium,
                'price' => $reseller === null ? null : $this->withMargin((int) round((float) ($reseller['price'] ?? 0) * 100)),
                'currency' => (string) ($reseller['currency'] ?? 'EUR'),
            ];
        }

        return $results;
    }

    public function register(string $domain, array $registrant, array $nameservers): string
    {
        [$name, $extension] = $this->split($domain);

        $this->guardSupportedTld($extension);

        $payload = [
            'domain' => ['name' => $name, 'extension' => $extension],
            'period' => 1,
            // GDPR-friendly default: the registrant's contact details never
            // appear in public WHOIS/RDAP output.
            'is_private_whois_enabled' => true,
            'owner' => $this->owner($registrant),
        ];

        // Empty = Openprovider's own defaults, see Registrar::register().
        $nameservers = $this->nameserverList($nameservers, allowEmpty: true);

        if ($nameservers !== []) {
            $payload['name_servers'] = $this->nameServerObjects($nameservers);
        }

        $response = $this->post('/domains', $payload, 'domain registration', idempotent: false);

        $domainId = $response->json('data.id');

        if (! $domainId) {
            throw new RuntimeException('Openprovider did not return a domain id.');
        }

        return (string) $domainId;
    }

    public function renew(string $domain, string $registrarDomainId, int $years = 1): void
    {
        $this->post("/domains/{$registrarDomainId}/renew", ['period' => max(1, $years)], 'domain renewal', idempotent: false);
    }

    public function transferIn(string $domain, string $authCode, array $registrant): string
    {
        [$name, $extension] = $this->split($domain);

        $this->guardSupportedTld($extension);

        $response = $this->post('/domains/transfer', [
            'domain' => ['name' => $name, 'extension' => $extension],
            'auth_code' => $authCode,
            'is_private_whois_enabled' => true,
            'owner' => $this->owner($registrant),
        ], 'domain transfer request', idempotent: false);

        $domainId = $response->json('data.id');

        if (! $domainId) {
            throw new RuntimeException('Openprovider did not return a domain id.');
        }

        return (string) $domainId;
    }

    public function transferStatus(string $registrarDomainId): string
    {
        $response = $this->send(fn (PendingRequest $http) => $http->get($this->baseUrl."/domains/{$registrarDomainId}"), 'domain lookup', idempotent: true);

        return match ($response->json('data.status')) {
            'ACT' => 'completed',
            'FAI', 'CAN' => 'failed',
            default => 'pending',
        };
    }

    public function configureNameservers(string $domain, string $registrarDomainId, array $nameservers): void
    {
        $this->put("/domains/{$registrarDomainId}", [
            'name_servers' => $this->nameServerObjects($this->nameserverList($nameservers)),
        ], 'nameserver update');
    }

    public function getAuthCode(string $domain, string $registrarDomainId): string
    {
        $response = $this->send(fn (PendingRequest $http) => $http->get($this->baseUrl."/domains/{$registrarDomainId}/auth-code"), 'auth code request', idempotent: true);

        $authCode = $response->json('data.auth_code');

        if (! $authCode) {
            throw new RuntimeException('Openprovider did not return an auth code.');
        }

        return (string) $authCode;
    }

    public function setTransferLock(string $domain, string $registrarDomainId, bool $locked): void
    {
        $this->put("/domains/{$registrarDomainId}", ['is_locked' => $locked], 'transfer lock update');
    }

    /**
     * @param  array{name: string, email: string, phone: string, address: string, city: string, postal_code: string, country: string, state?: string}  $registrant
     * @return array<string, mixed>
     */
    protected function owner(array $registrant): array
    {
        return [
            'name' => ['full_name' => (string) ($registrant['name'] ?? '')],
            'email' => (string) ($registrant['email'] ?? ''),
            'phone' => ['full_number' => (string) ($registrant['phone'] ?? '')],
            'address' => array_filter([
                'street' => (string) ($registrant['address'] ?? ''),
                'city' => (string) ($registrant['city'] ?? ''),
                'state' => (string) ($registrant['state'] ?? ''),
                'zipcode' => (string) ($registrant['postal_code'] ?? ''),
                'country' => strtoupper((string) ($registrant['country'] ?? '')),
            ], fn ($value) => $value !== ''),
        ];
    }

    /**
     * Openprovider wants nameservers as objects, not bare hostnames.
     *
     * @param  list<string>  $nameservers
     * @return list<array{name: string}>
     */
    protected function nameServerObjects(array $nameservers): array
    {
        return array_map(fn (string $ns) => ['name' => $ns], $nameservers);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function post(string $path, array $payload, string $what, bool $idempotent): Response
    {
        return $this->send(fn (PendingRequest $http) => $http->post($this->baseUrl.$path, $payload), $what, $idempotent);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function put(string $path, array $payload, string $what): Response
    {
        return $this->send(fn (PendingRequest $http) => $http->put($this->baseUrl.$path, $payload), $what, idempotent: true);
    }

    /**
     * @param  callable(PendingRequest): Response  $call
     */
    protected function send(callable $call, string $what, bool $idempotent): Response
    {
        $request = Http::withToken($this->token())->acceptJson()->asJson()->timeout(30);

        try {
            $response = $call($idempotent ? $this->retrying($request) : $request);
        } catch (ConnectionException $e) {
            throw new RuntimeException("Openprovider is unreachable ({$what}): ".$e->getMessage(), previous: $e);
        }

        if ($response->failed()) {
            $detail = (string) ($response->json('desc') ?? $response->body());

            throw new RuntimeException("Openprovider {$what} failed: ".($detail !== '' ? $detail : "HTTP {$response->status()}"));
        }

        return $response;
    }

    protected function token(): string
    {
        try {
            $response = $this->retrying(Http::acceptJson()->asJson()->timeout(30))->post($this->baseUrl.'/auth/login', [
                'username' => $this->username,
                'password' => $this->password,
            ]);
        } catch (ConnectionException $e) {
            throw new RuntimeException('Openprovider is unreachable (authentication): '.$e->getMessage(), previous: $e);
        }

        if ($response->failed()) {
            throw new RuntimeException('Openprovider authentication failed: '.$response->body());
        }

        $token = $response->json('data.token');

        if (! $token) {
            throw new RuntimeException('Openprovider did not return an auth token.');
        }

        return (string) $token;
    }
}
