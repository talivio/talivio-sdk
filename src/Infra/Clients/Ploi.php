<?php

namespace Talivio\Sdk\Infra\Clients;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;
use Talivio\Sdk\Infra\Contracts\Host;
use Talivio\Sdk\Infra\Exceptions\NotConfiguredException;
use Talivio\Sdk\Infra\Support\RetriesTransientFailures;

/**
 * Ploi (https://developers.ploi.io) — the server every Talivio product
 * runs on. Plain HTTP client against https://ploi.io/api, no SDK.
 *
 * A product attaches customer domains to its ONE site in one of two ways
 * (`talivio.infra.ploi.attach_mode`), both live-proven and both kept
 * because switching a product would orphan the domains it already has:
 *
 *  - `tenant` (default; Shops): Ploi's multi-tenancy feature — a tenant
 *    is an extra vhost the site answers for, with its OWN Let's Encrypt
 *    certificate requested per tenant (and DNS-01 validation possible).
 *  - `alias` (Contentio): the domain is added to the site's alias list
 *    (nginx server_name) and a certificate is requested on the SITE for
 *    it. ⚠️ Ploi's alias endpoint REPLACES the whole list, so the current
 *    set is always sent plus the new one — a bare single-element array
 *    would silently drop every other customer's domain.
 *
 * The site-level methods (listSites/createSite/deleteSite/…) are for
 * operations work on OTHER sites of the same server; they need no site
 * id in config.
 *
 * ⚠️ Ploi tokens carry an IP allowlist. A 403 with "This IP address is not
 * allowed" is NOT a scope problem — the calling host must be added in
 * Ploi → Profile → API tokens. Ploi rate-limits tokens (~60 req/min);
 * nothing here polls tightly. Ploi caps per_page at 50 regardless of
 * what's asked, so every list follows pagination — a single unpaginated
 * call MISSED the Contentio site entirely once (it sat on page 2).
 */
class Ploi implements Host
{
    use RetriesTransientFailures;

    public const NAME = 'ploi';

    public const MODE_TENANT = 'tenant';

    public const MODE_ALIAS = 'alias';

    /** A server's IP doesn't move; look it up once an hour at most. */
    protected const SERVER_IP_CACHE_SECONDS = 3600;

    /**
     * @param  string|null  $serverIp  explicit override — handy behind a floating IP or load balancer that isn't the Ploi server's own address
     * @param  string|null  $dnsCredentialId  id of a DNS-provider credential saved on the Ploi profile, for DNS-01 tenant certificates
     * @param  string|null  $dnsToken  a SCOPED Cloudflare token handed to Ploi inline for DNS-01 when no profile credential exists (the Global API Key can't be used — certbot's Cloudflare plugin wants a token)
     */
    public function __construct(
        protected string $token,
        protected int|string $serverId,
        protected int|string|null $siteId = null,
        protected string $mode = self::MODE_TENANT,
        protected ?string $serverIp = null,
        protected ?string $dnsCredentialId = null,
        protected ?string $dnsToken = null,
        protected string $baseUrl = 'https://ploi.io/api',
    ) {
        if (! in_array($this->mode, [self::MODE_TENANT, self::MODE_ALIAS], true)) {
            throw new InvalidArgumentException("Unknown Ploi attach mode \"{$this->mode}\" — expected \"tenant\" or \"alias\".");
        }
    }

    /**
     * Null without a token and server id. The site id is NOT required —
     * the ops use case (creating sites) has none, and the check command
     * runs before it's known; the platform-site methods throw
     * NotConfiguredException when it's missing.
     */
    public static function fromConfig(): ?static
    {
        $cfg = (array) config('talivio.infra.ploi', []);

        if (blank($cfg['api_token'] ?? null) || blank($cfg['server_id'] ?? null)) {
            return null;
        }

        $cloudflare = (array) config('talivio.infra.cloudflare', []);
        $scopedToken = filled($cloudflare['api_token'] ?? null) ? (string) $cloudflare['api_token'] : null;

        return new static(
            token: (string) $cfg['api_token'],
            serverId: (string) $cfg['server_id'],
            siteId: filled($cfg['site_id'] ?? null) ? (string) $cfg['site_id'] : null,
            mode: (string) ($cfg['attach_mode'] ?? self::MODE_TENANT),
            serverIp: filled($cfg['server_ip'] ?? null) ? (string) $cfg['server_ip'] : null,
            dnsCredentialId: filled($cfg['dns_credential_id'] ?? null) ? (string) $cfg['dns_credential_id'] : null,
            dnsToken: $scopedToken,
            baseUrl: rtrim((string) ($cfg['base_url'] ?? 'https://ploi.io/api'), '/'),
        );
    }

    /** @return list<string> */
    public static function requiredEnv(): array
    {
        return ['PLOI_API_TOKEN', 'PLOI_SERVER_ID'];
    }

    public function mode(): string
    {
        return $this->mode;
    }

    public function serverId(): string
    {
        return (string) $this->serverId;
    }

    public function siteId(): ?string
    {
        return $this->siteId === null ? null : (string) $this->siteId;
    }

    // ------------------------------------------------------------------
    // Platform site
    // ------------------------------------------------------------------

    public function serverIp(): string
    {
        if (filled($this->serverIp)) {
            return (string) $this->serverIp;
        }

        return Cache::remember('ploi:server-ip:'.$this->serverId, self::SERVER_IP_CACHE_SECONDS, function () {
            $server = $this->call(fn (PendingRequest $http) => $http->get($this->serverUrl()), 'server lookup');

            $ip = (string) ($server['data']['ip_address'] ?? '');

            if ($ip === '') {
                throw new RuntimeException('Ploi did not return the server IP address.');
            }

            return $ip;
        });
    }

    public function attachDomain(string $domain): void
    {
        $domain = $this->normalize($domain);

        if ($this->mode === self::MODE_ALIAS) {
            $current = $this->aliases($this->platformSiteId());

            if (in_array($domain, $current, true)) {
                return;
            }

            $this->call(fn (PendingRequest $http) => $http->post($this->siteUrl().'/aliases', [
                'aliases' => [...$current, $domain],
            ]), 'alias creation');

            return;
        }

        if (in_array($domain, $this->tenants(), true)) {
            return;
        }

        $this->call(fn (PendingRequest $http) => $http->post($this->siteUrl().'/tenants', [
            'tenants' => [$domain],
        ]), 'tenant creation');
    }

    public function requestCertificate(string $domain, array $domains = [], ?string $webhookUrl = null, bool $validateViaDns = false): void
    {
        $domain = $this->normalize($domain);
        $domains = array_values(array_unique(array_map(fn ($d) => $this->normalize((string) $d), [$domain, ...$domains])));

        if ($this->mode === self::MODE_ALIAS) {
            if ($validateViaDns) {
                Log::warning('Ploi: DNS validation is only available for tenant certificates; the site certificate for '.$domain.' will validate over HTTP.');
            }

            $this->requestSiteCertificate($this->platformSiteId(), $domains, $webhookUrl);

            return;
        }

        $payload = ['domains' => implode(',', $domains)];

        if ($webhookUrl !== null) {
            $payload['webhook'] = $webhookUrl;
        }

        if ($validateViaDns && ($additional = $this->dnsValidation()) !== null) {
            $payload['additional'] = $additional;
        }

        // Not idempotent at Ploi (a second request queues a second
        // issuance) — never retried.
        $this->call(fn (PendingRequest $http) => $http->post($this->siteUrl()."/tenants/{$domain}/request-certificate", $payload), 'certificate request', retry: false);
    }

    public function certificateIssued(string $domain): bool
    {
        return $this->siteCertificateIssued($this->platformSiteId(), $domain);
    }

    public function detachDomain(string $domain): void
    {
        $domain = $this->normalize($domain);

        if ($this->mode === self::MODE_ALIAS) {
            $siteId = $this->platformSiteId();

            foreach ($this->certificates($siteId) as $certificate) {
                if (in_array($domain, $this->coveredDomains($certificate), true)) {
                    $response = $this->send(fn (PendingRequest $http) => $http->delete($this->siteUrl($siteId)."/certificates/{$certificate['id']}"), 'certificate deletion');

                    if ($response->status() !== 404) {
                        $this->result($response, 'certificate deletion');
                    }
                }
            }

            if (! in_array($domain, $this->aliases($siteId), true)) {
                return;
            }

            $response = $this->send(fn (PendingRequest $http) => $http->delete($this->siteUrl($siteId)."/aliases/{$domain}"), 'alias deletion');

            if ($response->status() !== 404) {
                $this->result($response, 'alias deletion');
            }

            return;
        }

        $response = $this->send(fn (PendingRequest $http) => $http->delete($this->siteUrl()."/tenants/{$domain}"), 'tenant deletion');

        if ($response->status() === 404) {
            return;
        }

        $this->result($response, 'tenant deletion');
    }

    // ------------------------------------------------------------------
    // Sites on the server
    // ------------------------------------------------------------------

    public function listSites(): array
    {
        $sites = [];
        $page = 1;

        do {
            $response = $this->call(fn (PendingRequest $http) => $http->get($this->serverUrl().'/sites', ['page' => $page, 'per_page' => 50]), 'site listing');

            foreach ((array) ($response['data'] ?? []) as $site) {
                $sites[] = [
                    'id' => (int) ($site['id'] ?? 0),
                    'domain' => (string) ($site['domain'] ?? ''),
                    'aliases' => array_values(array_map('strval', (array) ($site['aliases'] ?? []))),
                ];
            }

            $lastPage = (int) ($response['meta']['last_page'] ?? 1);
        } while (++$page <= $lastPage);

        return $sites;
    }

    /**
     * @return array<string, mixed> the site as Ploi returns it (`data`)
     */
    public function site(int|string|null $siteId = null): array
    {
        $response = $this->call(fn (PendingRequest $http) => $http->get($this->siteUrl($siteId ?? $this->platformSiteId())), 'site lookup');

        return (array) ($response['data'] ?? []);
    }

    public function createSite(string $domain, array $options = []): array
    {
        $domain = $this->normalize($domain);

        $response = $this->call(fn (PendingRequest $http) => $http->post($this->serverUrl().'/sites', array_merge([
            'root_domain' => $domain,
            'web_directory' => '/public',
            'project_root' => '/',
        ], $options)), 'site creation', retry: false);

        $id = (int) ($response['data']['id'] ?? 0);

        if ($id === 0) {
            throw new RuntimeException('Ploi did not return an id for the new site.');
        }

        return ['id' => $id, 'domain' => (string) ($response['data']['domain'] ?? $domain)];
    }

    public function deleteSite(int|string $siteId): void
    {
        $response = $this->send(fn (PendingRequest $http) => $http->delete($this->siteUrl($siteId)), 'site deletion');

        if ($response->status() === 404) {
            return;
        }

        $this->result($response, 'site deletion');
    }

    public function requestSiteCertificate(int|string $siteId, array $domains, ?string $webhookUrl = null): void
    {
        $domains = array_values(array_unique(array_map(fn ($d) => $this->normalize((string) $d), $domains)));

        if ($domains === []) {
            throw new RuntimeException('No domains given to request a certificate for.');
        }

        // A certificate already covering the primary name (pending or
        // active) is not requested again — a retry after a failed poll
        // must find the existing one.
        foreach ($this->certificates($siteId) as $certificate) {
            if (in_array($domains[0], $this->coveredDomains($certificate), true)) {
                return;
            }
        }

        $payload = ['certificate' => implode(',', $domains), 'type' => 'letsencrypt'];

        if ($webhookUrl !== null) {
            $payload['webhook'] = $webhookUrl;
        }

        $this->call(fn (PendingRequest $http) => $http->post($this->siteUrl($siteId).'/certificates', $payload), 'certificate request', retry: false);
    }

    public function siteCertificateIssued(int|string $siteId, string $domain): bool
    {
        $domain = $this->normalize($domain);

        foreach ($this->certificates($siteId) as $certificate) {
            if (! in_array($certificate['status'] ?? null, ['active', 'issued'], true)) {
                continue;
            }

            if (in_array($domain, $this->coveredDomains($certificate), true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every certificate on a site, following pagination (a platform site
     * with many tenants has many certificates).
     *
     * @return list<array<string, mixed>>
     */
    public function certificates(int|string|null $siteId = null): array
    {
        $url = $this->siteUrl($siteId ?? $this->platformSiteId()).'/certificates';
        $certificates = [];

        while ($url !== null) {
            $page = $this->call(fn (PendingRequest $http) => $http->get($url), 'certificate lookup');

            array_push($certificates, ...(array) ($page['data'] ?? []));

            $url = $page['links']['next'] ?? null;
        }

        return $certificates;
    }

    // ------------------------------------------------------------------

    /**
     * Ploi's DNS-01 option for a tenant certificate: either a DNS-provider
     * credential saved on the Ploi profile or the scoped Cloudflare token
     * handed over inline. With neither, null — the request falls back to
     * HTTP-01.
     *
     * @return array<string, mixed>|null
     */
    protected function dnsValidation(): ?array
    {
        if (filled($this->dnsCredentialId)) {
            return ['use_dns_provider' => true, 'use_from_profile' => true, 'credential' => (int) $this->dnsCredentialId];
        }

        if (filled($this->dnsToken)) {
            return ['use_dns_provider' => true, 'provider' => 'cloudflare', 'secret' => (string) $this->dnsToken];
        }

        Log::warning('Ploi: DNS validation requested but no usable DNS credential is configured (PLOI_DNS_CREDENTIAL_ID or a scoped CLOUDFLARE_API_TOKEN) — falling back to HTTP validation.');

        return null;
    }

    /**
     * @return list<string>
     */
    protected function tenants(): array
    {
        $result = $this->call(fn (PendingRequest $http) => $http->get($this->siteUrl().'/tenants'), 'tenant lookup');

        return array_values(array_map(fn ($tenant) => $this->normalize((string) $tenant), (array) ($result['data']['tenants'] ?? [])));
    }

    /**
     * @return list<string>
     */
    protected function aliases(int|string $siteId): array
    {
        return array_values(array_map(fn ($alias) => $this->normalize((string) $alias), (array) ($this->site($siteId)['aliases'] ?? [])));
    }

    /**
     * @param  array<string, mixed>  $certificate
     * @return list<string>
     */
    protected function coveredDomains(array $certificate): array
    {
        return array_values(array_filter(array_map(fn ($d) => $this->normalize((string) $d), explode(',', (string) ($certificate['domain'] ?? '')))));
    }

    protected function normalize(string $domain): string
    {
        return strtolower(trim($domain));
    }

    protected function platformSiteId(): string
    {
        if ($this->siteId === null || (string) $this->siteId === '') {
            throw NotConfiguredException::forService('Ploi (platform site)', ['PLOI_SITE_ID']);
        }

        return (string) $this->siteId;
    }

    protected function serverUrl(): string
    {
        return $this->baseUrl.'/servers/'.$this->serverId;
    }

    protected function siteUrl(int|string|null $siteId = null): string
    {
        return $this->serverUrl().'/sites/'.($siteId ?? $this->platformSiteId());
    }

    protected function request(bool $retry): PendingRequest
    {
        $request = Http::withToken($this->token)->acceptJson()->asJson()->timeout(30);

        return $retry ? $this->retrying($request) : $request;
    }

    /**
     * @param  callable(PendingRequest): Response  $call
     */
    protected function send(callable $call, string $what, bool $retry = true): Response
    {
        try {
            return $call($this->request($retry));
        } catch (ConnectionException $e) {
            throw new RuntimeException("Ploi is unreachable ({$what}): ".$e->getMessage(), previous: $e);
        }
    }

    /**
     * @param  callable(PendingRequest): Response  $call
     * @return array<string, mixed> the decoded JSON body
     */
    protected function call(callable $call, string $what, bool $retry = true): array
    {
        return $this->result($this->send($call, $what, $retry), $what);
    }

    /**
     * @return array<string, mixed>
     */
    protected function result(Response $response, string $what): array
    {
        if ($response->failed()) {
            $detail = (string) ($response->json('message') ?? '');

            foreach ((array) $response->json('errors', []) as $messages) {
                $detail .= ' '.implode(' ', (array) $messages);
            }

            if ($response->status() === 403 && str_contains($detail, 'IP address')) {
                $detail .= ' (Ploi\'s per-token IP allowlist, not a scope problem — add this server\'s public IP under Ploi → Profile → API tokens.)';
            }

            throw new RuntimeException("Ploi {$what} failed: ".(trim($detail) !== '' ? trim($detail) : "HTTP {$response->status()}"));
        }

        return (array) $response->json();
    }
}
