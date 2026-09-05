<?php

namespace Talivio\Sdk\Infra\Clients;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Talivio\Sdk\Infra\Contracts\Mail;
use Talivio\Sdk\Infra\Exceptions\HostRefusedException;
use Talivio\Sdk\Infra\Support\MailOwner;
use Talivio\Sdk\Infra\Support\RetriesTransientFailures;

/**
 * mailcow's admin API (`talivio.infra.mailcow`) — one shared mailcow
 * instance hosts mailboxes/aliases for every customer domain across every
 * Talivio product. JSON, auth via a single `X-API-Key` header.
 *
 * ⚠️ mailcow answers HTTP 200 even for a failed write — errors show up as
 * `[{"type":"error","msg":[...]}]` in the body, not the status code, so
 * call() inspects the payload itself rather than trusting the status.
 *
 * ⚠️ Units are inconsistent on mailcow's side and this class does NOT
 * paper over it: writes take megabytes (add/mailbox `quota`), reads give
 * bytes (get/mailbox `quota`, `quota_used`). Method names say which —
 * anything `…Mb` is megabytes, mailboxQuota() is bytes.
 *
 * ⚠️ Same IP-allowlist trap as Ploi/Namecheap/Cloudflare: "Allow from"
 * under mailcow's Configuration → Access → API must include the calling
 * server's outbound IP.
 */
class Mailcow implements Mail
{
    use RetriesTransientFailures;

    public const NAME = 'mailcow';

    /**
     * @param  string|null  $mxHost  the hostname customer domains point their MX at
     * @param  string|null  $spfValue  the SPF TXT customer domains publish (e.g. "v=spf1 mx -all")
     * @param  string  $description  what new domains are labelled with when no MailOwner is given
     */
    public function __construct(
        protected string $baseUrl,
        protected string $apiKey,
        protected ?string $mxHost = null,
        protected ?string $spfValue = null,
        protected string $description = 'Talivio',
    ) {
        $this->baseUrl = rtrim($this->baseUrl, '/');
    }

    public static function fromConfig(): ?static
    {
        $cfg = (array) config('talivio.infra.mailcow', []);

        if (blank($cfg['url'] ?? null) || blank($cfg['api_key'] ?? null)) {
            return null;
        }

        return new static(
            baseUrl: (string) $cfg['url'],
            apiKey: (string) $cfg['api_key'],
            mxHost: filled($cfg['mx_host'] ?? null) ? (string) $cfg['mx_host'] : null,
            spfValue: filled($cfg['spf_value'] ?? null) ? (string) $cfg['spf_value'] : null,
            description: (string) ($cfg['description'] ?? 'Talivio'),
        );
    }

    /** @return list<string> */
    public static function requiredEnv(): array
    {
        return ['MAILCOW_URL', 'MAILCOW_API_KEY'];
    }

    // ------------------------------------------------------------------
    // Domains
    // ------------------------------------------------------------------

    public function addDomain(
        string $domain,
        int $maxMailboxes = 10,
        int $maxQuotaMb = 10240,
        bool $active = true,
        ?MailOwner $owner = null,
        ?int $defaultQuotaMb = null,
        ?int $totalQuotaMb = null,
        ?int $maxAliases = null,
    ): void {
        $domain = $this->normalize($domain);

        // mailcow's get endpoint returns an empty body (not a 404) for an
        // unknown domain, so the presence of "domain_name" is what tells
        // the two cases apart — and add/domain on an existing domain is
        // a "danger" reply, not a no-op, so the check has to come first.
        if ($this->domain($domain) !== null) {
            return;
        }

        $payload = [
            'domain' => $domain,
            'description' => $owner?->toDescription() ?? $this->description,
            'aliases' => $maxAliases ?? 400,
            'mailboxes' => $maxMailboxes,
            'defquota' => $defaultQuotaMb ?? 1024,
            'maxquota' => $maxQuotaMb,
            'quota' => $totalQuotaMb ?? $maxQuotaMb,
            'active' => $active ? 1 : 0,
            'restart_sogo' => 1,
        ];

        $this->call('POST', '/api/v1/add/domain', $payload);
    }

    public function listDomains(): array
    {
        $result = $this->call('GET', '/api/v1/get/domain/all');

        return is_array($result) ? array_values(array_filter($result, 'is_array')) : [];
    }

    public function domain(string $domain): ?array
    {
        $result = $this->call('GET', '/api/v1/get/domain/'.$this->normalize($domain));

        if (! is_array($result) || blank($result['domain_name'] ?? null)) {
            return null;
        }

        return $result;
    }

    public function setDomainActive(string $domain, bool $active): void
    {
        $this->call('POST', '/api/v1/edit/domain', [
            'items' => [$this->normalize($domain)],
            'attr' => ['active' => $active ? '1' : '0'],
        ]);
    }

    public function deleteDomain(string $domain): void
    {
        $this->call('POST', '/api/v1/delete/domain', [$this->normalize($domain)], ignoreNotFound: true);
    }

    // ------------------------------------------------------------------
    // DNS
    // ------------------------------------------------------------------

    public function dkim(string $domain): ?array
    {
        $result = $this->call('GET', '/api/v1/get/dkim/'.$this->normalize($domain));

        if (! is_array($result) || blank($result['dkim_txt'] ?? null)) {
            return null;
        }

        return [
            'selector' => (string) ($result['dkim_selector'] ?? 'dkim'),
            'record' => (string) $result['dkim_txt'],
        ];
    }

    public function dnsRecords(string $domain): array
    {
        $domain = $this->normalize($domain);
        $records = [];

        if (filled($this->mxHost)) {
            $records[] = ['type' => 'MX', 'name' => $domain, 'content' => (string) $this->mxHost, 'priority' => 10];
        }

        if (filled($this->spfValue)) {
            $records[] = ['type' => 'TXT', 'name' => $domain, 'content' => (string) $this->spfValue];
        }

        // Quarantine rather than reject: a customer's first mis-sent
        // newsletter shouldn't bounce outright while they learn the setup.
        $records[] = ['type' => 'TXT', 'name' => "_dmarc.{$domain}", 'content' => "v=DMARC1; p=quarantine; rua=mailto:postmaster@{$domain}"];

        if ($dkim = $this->dkim($domain)) {
            $records[] = ['type' => 'TXT', 'name' => $dkim['selector'].'._domainkey.'.$domain, 'content' => $dkim['record']];
        }

        return $records;
    }

    // ------------------------------------------------------------------
    // Mailboxes
    // ------------------------------------------------------------------

    public function addMailbox(string $domain, string $localPart, string $password, string $name = '', ?int $quotaMb = null): void
    {
        $payload = [
            'local_part' => strtolower($localPart),
            'domain' => $this->normalize($domain),
            'name' => $name !== '' ? $name : $localPart,
            'password' => $password,
            'password2' => $password,
            'active' => 1,
        ];

        if ($quotaMb !== null) {
            $payload['quota'] = $quotaMb;
        }

        $this->call('POST', '/api/v1/add/mailbox', $payload);
    }

    public function mailbox(string $email): ?array
    {
        $result = $this->call('GET', '/api/v1/get/mailbox/'.strtolower(trim($email)));

        if (! is_array($result) || blank($result['username'] ?? null)) {
            return null;
        }

        return $result;
    }

    public function updateMailbox(string $email, array $changes): void
    {
        $attr = [];

        if (isset($changes['password'])) {
            $attr['password'] = $changes['password'];
            $attr['password2'] = $changes['password'];
        }

        if (isset($changes['name'])) {
            $attr['name'] = $changes['name'];
        }

        if (isset($changes['quota_mb'])) {
            $attr['quota'] = (int) $changes['quota_mb'];
        }

        if (isset($changes['active'])) {
            $attr['active'] = $changes['active'] ? '1' : '0';
        }

        // Replaces the whole list; an empty array stops forwarding. Uses
        // array_key_exists, not isset, so [] is honoured rather than skipped.
        if (array_key_exists('forward_to', $changes)) {
            $attr['forward_to'] = implode(',', (array) $changes['forward_to']);
        }

        if (isset($changes['forward_only'])) {
            $attr['forward_only'] = $changes['forward_only'] ? '1' : '0';
        }

        if ($attr === []) {
            return;
        }

        $this->call('POST', '/api/v1/edit/mailbox', [
            'items' => [strtolower(trim($email))],
            'attr' => $attr,
        ]);
    }

    public function setMailboxesActive(array $emails, bool $active): void
    {
        $emails = array_values(array_filter(array_map(fn ($e) => strtolower(trim((string) $e)), $emails)));

        if ($emails === []) {
            return;
        }

        $this->call('POST', '/api/v1/edit/mailbox', [
            'items' => $emails,
            'attr' => ['active' => $active ? '1' : '0'],
        ]);
    }

    public function mailboxQuota(string $email): array
    {
        $mailbox = $this->mailbox($email);

        if ($mailbox === null) {
            return ['used' => 0, 'total' => 0, 'percent' => 0.0];
        }

        $used = (int) ($mailbox['quota_used'] ?? 0);
        $total = (int) ($mailbox['quota'] ?? 0);

        return [
            'used' => $used,
            'total' => $total,
            'percent' => $total > 0 ? round($used / $total * 100, 1) : 0.0,
        ];
    }

    public function deleteMailbox(string $email): void
    {
        $this->call('POST', '/api/v1/delete/mailbox', [strtolower(trim($email))], ignoreNotFound: true);
    }

    public function listMailboxes(string $domain): array
    {
        $result = $this->call('GET', '/api/v1/get/mailbox/all/'.$this->normalize($domain));

        return is_array($result) ? array_values(array_filter($result, 'is_array')) : [];
    }

    // ------------------------------------------------------------------
    // Aliases
    // ------------------------------------------------------------------

    public function addAlias(string $address, string $goto): void
    {
        $this->call('POST', '/api/v1/add/alias', [
            'address' => strtolower(trim($address)),
            'goto' => $goto,
            'active' => 1,
        ]);
    }

    /**
     * Unlike a mailbox (whose mailcow primary key IS its address), an
     * alias has its own autoincrement id and delete/alias wants that id,
     * not the address — confirmed live in Shops 2026-08-30. Idempotent:
     * an address with no matching alias is treated as already deleted.
     */
    public function deleteAlias(string $address): void
    {
        $address = strtolower(trim($address));
        $domain = (string) substr(strrchr($address, '@') ?: '', 1);

        foreach ($this->listAliases($domain) as $alias) {
            if (strcasecmp((string) ($alias['address'] ?? ''), $address) === 0 && filled($alias['id'] ?? null)) {
                $this->deleteAliasById((int) $alias['id']);

                return;
            }
        }
    }

    public function deleteAliasById(int $aliasId): void
    {
        $this->call('POST', '/api/v1/delete/alias', [(string) $aliasId], ignoreNotFound: true);
    }

    public function updateAlias(int $aliasId, array $changes): void
    {
        $attr = array_filter([
            'address' => $changes['address'] ?? null,
            'goto' => $changes['goto'] ?? null,
        ], fn ($value) => $value !== null);

        if (isset($changes['active'])) {
            $attr['active'] = $changes['active'] ? '1' : '0';
        }

        if ($attr === []) {
            return;
        }

        $this->call('POST', '/api/v1/edit/alias', [
            'items' => [(string) $aliasId],
            'attr' => $attr,
        ]);
    }

    public function listAliases(string $domain): array
    {
        $result = $this->call('GET', '/api/v1/get/alias/all/'.$this->normalize($domain));

        return is_array($result) ? array_values(array_filter($result, 'is_array')) : [];
    }

    public function countAliases(array $domains): int
    {
        $total = 0;

        foreach ($domains as $domain) {
            $total += count($this->listAliases((string) $domain));
        }

        return $total;
    }

    // ------------------------------------------------------------------
    // Usage
    // ------------------------------------------------------------------

    public function resourceSummary(array $domains): array
    {
        $mailboxes = 0;
        $aliases = 0;
        $used = 0;
        $quota = 0;

        foreach ($domains as $domain) {
            $domain = (string) $domain;
            $rows = $this->listMailboxes($domain);

            $mailboxes += count($rows);
            $aliases += count($this->listAliases($domain));

            foreach ($rows as $row) {
                $used += (int) ($row['quota_used'] ?? 0);
                $quota += (int) ($row['quota'] ?? 0);
            }
        }

        return [
            'mailboxes' => $mailboxes,
            'aliases' => $aliases,
            'used_bytes' => $used,
            'quota_bytes' => $quota,
            'usage_percent' => $quota > 0 ? round($used / $quota * 100, 1) : 0.0,
        ];
    }

    // ------------------------------------------------------------------
    // Sync jobs
    // ------------------------------------------------------------------

    public function listSyncJobs(): array
    {
        $result = $this->call('GET', '/api/v1/get/syncjobs/all/no_passwords');

        return is_array($result) ? array_values(array_filter($result, 'is_array')) : [];
    }

    public function addSyncJob(array $job): void
    {
        $this->call('POST', '/api/v1/add/syncjob', $job);
    }

    public function deleteSyncJob(int $jobId): void
    {
        $this->call('POST', '/api/v1/delete/syncjob', [(string) $jobId], ignoreNotFound: true);
    }

    // ------------------------------------------------------------------

    protected function normalize(string $domain): string
    {
        return strtolower(trim($domain));
    }

    /**
     * @param  array<int|string, mixed>  $body
     * @param  bool  $ignoreNotFound  a "does not exist" reply counts as success (deletes)
     */
    protected function call(string $method, string $path, array $body = [], bool $ignoreNotFound = false): mixed
    {
        try {
            $response = $method === 'GET'
                ? $this->retrying($this->request())->get($this->baseUrl.$path)
                : $this->request()->post($this->baseUrl.$path, $body);
        } catch (ConnectionException $e) {
            throw new RuntimeException("mailcow is unreachable ({$path}): ".$e->getMessage(), previous: $e);
        }

        if ($response->failed()) {
            throw new RuntimeException("mailcow {$path} failed with HTTP {$response->status()}.");
        }

        $json = $response->json();

        // Writes answer with a list of {type, msg} entries. "danger" is a
        // failure too (weak password, object already exists, quota
        // exceeded) — treating only "error" as one lets those through.
        $rows = is_array($json) && array_is_list($json) ? $json : [$json];

        foreach ($rows as $row) {
            if (! is_array($row) || ! in_array($row['type'] ?? null, ['error', 'danger'], true)) {
                continue;
            }

            $msg = $row['msg'] ?? 'unknown error';
            $msg = is_array($msg) ? implode(' ', array_map('strval', $msg)) : (string) $msg;

            if ($ignoreNotFound && (str_contains(strtolower($msg), 'does not exist') || str_contains(strtolower($msg), 'does_not_exist'))) {
                continue;
            }

            // mailcow answered and said no. Distinct from an outage: the
            // reason is worth showing the customer and retrying won't help.
            throw HostRefusedException::withReason('mailcow', $msg);
        }

        return $json;
    }

    protected function request(): PendingRequest
    {
        return Http::withHeaders(['X-API-Key' => $this->apiKey])->acceptJson()->asJson()->timeout(20);
    }
}
