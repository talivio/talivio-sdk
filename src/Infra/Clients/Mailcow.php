<?php

namespace Talivio\Sdk\Infra\Clients;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Talivio\Sdk\Infra\Contracts\Mail;
use Talivio\Sdk\Infra\Support\RetriesTransientFailures;

/**
 * mailcow's admin API (`talivio.infra.mailcow`) — one shared mailcow
 * instance hosts mailboxes/aliases for every customer domain with email
 * turned on. JSON, auth via a single `X-API-Key` header.
 *
 * mailcow answers HTTP 200 even for a failed write — errors show up as
 * `[{"type":"error","msg":[...]}]` in the body, not the status code, so
 * call() inspects the payload itself rather than trusting the status.
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
     * @param  string  $description  what new domains are labelled with in the mailcow UI
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

    public function addDomain(string $domain, int $maxMailboxes = 10, int $maxQuotaMb = 10240): void
    {
        $domain = strtolower(trim($domain));

        // mailcow's get endpoint returns an empty body (not a 404) for an
        // unknown domain, so the presence of "domain_name" is what tells
        // the two cases apart — and add/domain on an existing domain is
        // a "danger" reply, not a no-op, so the check has to come first.
        $existing = $this->call('GET', "/api/v1/get/domain/{$domain}");

        if (is_array($existing) && filled($existing['domain_name'] ?? null)) {
            return;
        }

        $this->call('POST', '/api/v1/add/domain', [
            'domain' => $domain,
            'description' => $this->description,
            'aliases' => 400,
            'mailboxes' => $maxMailboxes,
            'defquota' => 1024,
            'maxquota' => $maxQuotaMb,
            'quota' => $maxQuotaMb,
            'active' => 1,
            'restart_sogo' => 1,
        ]);
    }

    public function dkim(string $domain): ?array
    {
        $result = $this->call('GET', '/api/v1/get/dkim/'.strtolower(trim($domain)));

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
        $domain = strtolower(trim($domain));
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

    public function addMailbox(string $domain, string $localPart, string $password, string $name = ''): void
    {
        $this->call('POST', '/api/v1/add/mailbox', [
            'local_part' => $localPart,
            'domain' => strtolower(trim($domain)),
            'name' => $name !== '' ? $name : $localPart,
            'password' => $password,
            'password2' => $password,
            'quota' => 1024,
            'active' => 1,
        ]);
    }

    public function deleteMailbox(string $email): void
    {
        $this->call('POST', '/api/v1/delete/mailbox', [$email]);
    }

    public function listMailboxes(string $domain): array
    {
        $result = $this->call('GET', '/api/v1/get/mailbox/all/'.strtolower(trim($domain)));

        return is_array($result) ? array_values($result) : [];
    }

    public function addAlias(string $address, string $goto): void
    {
        $this->call('POST', '/api/v1/add/alias', [
            'address' => $address,
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
        $domain = strtolower(substr(strrchr($address, '@') ?: '', 1));

        foreach ($this->listAliases($domain) as $alias) {
            if (strcasecmp((string) ($alias['address'] ?? ''), $address) === 0 && filled($alias['id'] ?? null)) {
                $this->call('POST', '/api/v1/delete/alias', [(string) $alias['id']]);

                return;
            }
        }
    }

    /**
     * Removes the domain and every mailbox/alias on it. "Does not exist"
     * counts as success: the goal state either way is "not on mailcow".
     */
    public function deleteDomain(string $domain): void
    {
        $this->call('POST', '/api/v1/delete/domain', [strtolower(trim($domain))], ignoreNotFound: true);
    }

    public function listAliases(string $domain): array
    {
        $result = $this->call('GET', '/api/v1/get/alias/all/'.strtolower(trim($domain)));

        return is_array($result) ? array_values($result) : [];
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

            throw new RuntimeException("mailcow error: {$msg}");
        }

        return $json;
    }

    protected function request(): PendingRequest
    {
        return Http::withHeaders(['X-API-Key' => $this->apiKey])->acceptJson()->asJson()->timeout(20);
    }
}
