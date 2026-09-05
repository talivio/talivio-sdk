<?php

namespace Talivio\Sdk\Infra\Clients;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;
use Talivio\Sdk\Infra\Contracts\Dns;
use Talivio\Sdk\Infra\Support\RetriesTransientFailures;

/**
 * Cloudflare's v4 REST API, plain HTTP client (no SDK). Two ways to build
 * one:
 *  - fromConfig(): Talivio's OWN account (`talivio.infra.cloudflare`) —
 *    hosts a zone per domain Talivio registers or is NS-delegated.
 *  - withToken(): a CUSTOMER's zone-scoped token (Contentio's "publish
 *    the verification records for me" path). No account id, so it can't
 *    create zones — only findZoneId() + upsertRecord() make sense on it.
 *
 * Nameservers are assigned per ZONE (Cloudflare hands each zone a pair
 * out of the account's pool — in practice the same pair account-wide, but
 * not guaranteed), which is why ensureZone() returns them and the
 * registrar is told to delegate to exactly those rather than a static
 * list.
 *
 * Records are created DNS-only (grey cloud) unless `proxied` is on: Let's
 * Encrypt at the host validates over plain HTTP to the origin, and
 * proxying would also need the zone's SSL mode set to Full (strict) to
 * avoid a redirect loop — an account-level setting this class doesn't
 * manage.
 *
 * Auth is a scoped API token (Zone:Read, Zone:Edit, DNS:Edit on the
 * account — sent as a Bearer) or the legacy Global API Key + account
 * email (X-Auth-Email/X-Auth-Key, full-account, dev only). ⚠️ Only the
 * scoped token works for Ploi's DNS-01 certificate validation (certbot's
 * Cloudflare plugin refuses the global key).
 */
class Cloudflare implements Dns
{
    use RetriesTransientFailures;

    public const NAME = 'cloudflare';

    /** Cloudflare error code for "zone already exists on this account". */
    protected const ERROR_ZONE_EXISTS = 1061;

    public function __construct(
        protected ?string $token = null,
        protected ?string $key = null,
        protected ?string $email = null,
        protected ?string $accountId = null,
        protected string $baseUrl = 'https://api.cloudflare.com/client/v4',
        protected bool $proxied = false,
    ) {
        if (blank($this->token) && (blank($this->key) || blank($this->email))) {
            throw new InvalidArgumentException('Cloudflare needs either an API token or a Global API Key + email.');
        }
    }

    /**
     * The platform's own account. Null when nothing is configured (local
     * dev without CLOUDFLARE_*).
     */
    public static function fromConfig(): ?static
    {
        $cfg = (array) config('talivio.infra.cloudflare', []);

        $token = filled($cfg['api_token'] ?? null) ? (string) $cfg['api_token'] : null;
        $key = filled($cfg['api_key'] ?? null) ? (string) $cfg['api_key'] : null;
        $email = filled($cfg['email'] ?? null) ? (string) $cfg['email'] : null;

        if ($token === null && ($key === null || $email === null)) {
            return null;
        }

        return new static(
            token: $token,
            key: $token === null ? $key : null,
            email: $token === null ? $email : null,
            accountId: filled($cfg['account_id'] ?? null) ? (string) $cfg['account_id'] : null,
            baseUrl: rtrim((string) ($cfg['base_url'] ?? 'https://api.cloudflare.com/client/v4'), '/'),
            proxied: (bool) ($cfg['proxied'] ?? false),
        );
    }

    /**
     * A client for SOMEONE ELSE's zone, reached through a token they gave
     * us. Base URL follows the platform config so tests can point both at
     * the same fake.
     */
    public static function withToken(string $token): static
    {
        return new static(
            token: $token,
            baseUrl: rtrim((string) config('talivio.infra.cloudflare.base_url', 'https://api.cloudflare.com/client/v4'), '/'),
        );
    }

    /** @return list<string> */
    public static function requiredEnv(): array
    {
        return ['CLOUDFLARE_API_TOKEN (or CLOUDFLARE_API_KEY + CLOUDFLARE_EMAIL)', 'CLOUDFLARE_ACCOUNT_ID'];
    }

    public function usesGlobalKey(): bool
    {
        return blank($this->token);
    }

    public function ensureZone(string $domain, bool $jumpStart = false): array
    {
        $domain = strtolower(trim($domain));

        if ($zone = $this->findZone($domain)) {
            return $this->zoneSummary($zone);
        }

        $response = $this->send(fn (PendingRequest $http) => $http->post($this->baseUrl.'/zones', [
            'name' => $domain,
            'account' => ['id' => $this->accountId()],
            'type' => 'full',
            // For a fresh registration/transfer there's nothing to import
            // (jump_start would just pull in registrar parking-page
            // noise) — ensureRecords() writes exactly the records we
            // want. For a customer's existing domain, importing what's
            // there is what keeps their current email etc. alive across
            // the NS handover (see the contract docblock).
            'jump_start' => $jumpStart,
        ]), 'zone creation');

        // Raced by a concurrent attempt (or the list above missed a zone
        // the token can see but not list) — the zone exists, use it.
        if ($this->hasErrorCode($response, self::ERROR_ZONE_EXISTS) && ($zone = $this->findZone($domain))) {
            return $this->zoneSummary($zone);
        }

        return $this->zoneSummary($this->result($response, 'zone creation'));
    }

    public function zoneIsActive(string $zoneId): bool
    {
        $zone = $this->call(fn (PendingRequest $http) => $http->get($this->baseUrl."/zones/{$zoneId}"), 'zone lookup');

        return ($zone['status'] ?? null) === 'active';
    }

    public function findZoneId(string $domain): ?string
    {
        // Try the exact domain, then each parent — `a.b.example.com` → also
        // check `b.example.com` and `example.com`. Cloudflare's ?name= is an
        // exact match, so we ask per candidate rather than paging all zones.
        $labels = explode('.', strtolower(trim($domain)));

        for ($i = 0; $i < count($labels) - 1; $i++) {
            $candidate = implode('.', array_slice($labels, $i));

            $zones = $this->call(fn (PendingRequest $http) => $http->get($this->baseUrl.'/zones', [
                'name' => $candidate,
                'status' => 'active',
                'per_page' => 1,
            ]), 'zone lookup');

            if (($id = $zones[0]['id'] ?? null) !== null) {
                return (string) $id;
            }
        }

        return null;
    }

    public function ensureRecords(string $zoneId, string $domain, string $ipv4): void
    {
        $domain = strtolower(trim($domain));

        $this->upsertRecord($zoneId, 'A', $domain, $ipv4, $this->proxied);
        $this->upsertRecord($zoneId, 'CNAME', "www.{$domain}", $domain, $this->proxied);
    }

    public function upsertRecord(string $zoneId, string $type, string $name, string $content, ?bool $proxied = null, ?int $priority = null): void
    {
        $type = strtoupper($type);
        $name = strtolower(trim($name));
        $content = $type === 'CNAME' ? rtrim(strtolower(trim($content)), '.') : $content;

        $record = array_filter([
            'type' => $type,
            'name' => $name,
            'content' => $content,
            'ttl' => 1, // "auto"
            'proxied' => $proxied,
            'priority' => $priority,
        ], fn ($value) => $value !== null);

        $existing = $this->call(fn (PendingRequest $http) => $http->get($this->baseUrl."/zones/{$zoneId}/dns_records", [
            'type' => $type,
            'name' => $name,
            'per_page' => 1,
        ]), 'DNS record lookup');

        $current = $existing[0] ?? null;

        if ($current === null) {
            $this->call(fn (PendingRequest $http) => $http->post($this->baseUrl."/zones/{$zoneId}/dns_records", $record), 'DNS record creation');

            return;
        }

        $unchanged = ($current['content'] ?? null) === $content
            && ($proxied === null || (bool) ($current['proxied'] ?? false) === $proxied)
            && ($priority === null || (int) ($current['priority'] ?? -1) === $priority);

        if ($unchanged) {
            return;
        }

        $this->call(fn (PendingRequest $http) => $http->patch($this->baseUrl."/zones/{$zoneId}/dns_records/{$current['id']}", $record), 'DNS record update');
    }

    public function listRecords(string $zoneId, ?string $nameContains = null): array
    {
        $records = [];
        $page = 1;

        do {
            $response = $this->send(fn (PendingRequest $http) => $http->get($this->baseUrl."/zones/{$zoneId}/dns_records", array_filter([
                'page' => $page,
                'per_page' => 100,
                'name.contains' => $nameContains,
            ], fn ($value) => $value !== null)), 'DNS record listing');

            array_push($records, ...(array) $this->result($response, 'DNS record listing'));

            $totalPages = (int) ($response->json('result_info.total_pages') ?? 1);
        } while (++$page <= $totalPages);

        return $records;
    }

    public function deleteZone(string $zoneId): void
    {
        $response = $this->send(fn (PendingRequest $http) => $http->delete($this->baseUrl."/zones/{$zoneId}"), 'zone deletion');

        if ($response->status() === 404) {
            return;
        }

        $this->result($response, 'zone deletion');
    }

    /**
     * Token mode: /user/tokens/verify must report `active`. Global-key mode
     * has no token to verify — /user answering 200 is the equivalent check.
     */
    public function verifyCredentials(): bool
    {
        if ($this->usesGlobalKey()) {
            return $this->send(fn (PendingRequest $http) => $http->get($this->baseUrl.'/user'), 'credential check')->successful();
        }

        $response = $this->send(fn (PendingRequest $http) => $http->get($this->baseUrl.'/user/tokens/verify'), 'credential check');

        return $response->successful() && $response->json('result.status') === 'active';
    }

    /**
     * @return array<string, mixed>|null the zone as Cloudflare returns it
     */
    protected function findZone(string $domain): ?array
    {
        $zones = $this->call(fn (PendingRequest $http) => $http->get($this->baseUrl.'/zones', [
            'name' => $domain,
            'account.id' => $this->accountId(),
        ]), 'zone lookup');

        foreach ((array) $zones as $zone) {
            if (strcasecmp((string) ($zone['name'] ?? ''), $domain) === 0) {
                return $zone;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $zone
     * @return array{id: string, nameservers: list<string>, active: bool}
     */
    protected function zoneSummary(array $zone): array
    {
        $nameservers = array_values(array_filter(array_map('strval', (array) ($zone['name_servers'] ?? []))));

        if (($zone['id'] ?? '') === '' || $nameservers === []) {
            throw new RuntimeException('Cloudflare returned a zone without an id or nameservers.');
        }

        return [
            'id' => (string) $zone['id'],
            'nameservers' => $nameservers,
            'active' => ($zone['status'] ?? null) === 'active',
        ];
    }

    protected function accountId(): string
    {
        if (blank($this->accountId)) {
            throw new RuntimeException('Cloudflare is not configured for zone management (CLOUDFLARE_ACCOUNT_ID).');
        }

        return (string) $this->accountId;
    }

    protected function request(): PendingRequest
    {
        $request = $this->usesGlobalKey()
            ? Http::withHeaders(['X-Auth-Email' => (string) $this->email, 'X-Auth-Key' => (string) $this->key])
            : Http::withToken((string) $this->token);

        return $this->retrying($request->acceptJson()->asJson()->timeout(30));
    }

    /**
     * @param  callable(PendingRequest): Response  $call
     */
    protected function send(callable $call, string $what): Response
    {
        try {
            return $call($this->request());
        } catch (ConnectionException $e) {
            throw new RuntimeException("Cloudflare is unreachable ({$what}): ".$e->getMessage(), previous: $e);
        }
    }

    /**
     * send() + result() for the common case.
     *
     * @param  callable(PendingRequest): Response  $call
     */
    protected function call(callable $call, string $what): mixed
    {
        return $this->result($this->send($call, $what), $what);
    }

    /**
     * Unwraps Cloudflare's {success, errors, result} envelope.
     *
     * @return mixed the `result` member
     */
    protected function result(Response $response, string $what): mixed
    {
        if ($response->failed() || $response->json('success') !== true) {
            $errors = collect($response->json('errors', []))
                ->map(fn ($error) => ($error['message'] ?? 'unknown error').' (#'.($error['code'] ?? '?').')')
                ->implode('; ');

            throw new RuntimeException("Cloudflare {$what} failed: ".($errors !== '' ? $errors : "HTTP {$response->status()}"));
        }

        return $response->json('result');
    }

    protected function hasErrorCode(Response $response, int $code): bool
    {
        return collect($response->json('errors', []))->contains(fn ($error) => (int) ($error['code'] ?? 0) === $code);
    }
}
