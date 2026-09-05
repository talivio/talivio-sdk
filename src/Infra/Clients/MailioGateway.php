<?php

namespace Talivio\Sdk\Infra\Clients;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Talivio\Sdk\Infra\Contracts\ProductMail;
use Talivio\Sdk\Infra\Exceptions\HostRefusedException;
use Talivio\Sdk\Infra\Support\RetriesTransientFailures;

/**
 * Talks to Mailio's server-to-server mail API (`/api/v1/mail/*`).
 *
 * Same shape as the Talivio Pay Gateway relationship: the product holds
 * one `tmail_…` key, Mailio decides what that key may reach, and the
 * operation set is closed. The product is never named in a request — the
 * key already says which product is calling, so a compromised product
 * cannot impersonate another.
 *
 * ⚠️ WHY NOT TALK TO mailcow DIRECTLY: the shared instance also carries
 * hand-run customer domains that belong to no product. Going through
 * Mailio is what makes "this isn't mine" enforceable instead of a
 * convention every product has to remember.
 *
 * Reads are retried on connection failures and 5xx; writes never are — a
 * resent createMailbox after an ambiguous failure could charge a plan slot
 * twice or resurrect an address the customer just deleted.
 */
class MailioGateway implements ProductMail
{
    use RetriesTransientFailures;

    public const NAME = 'mailio';

    public function __construct(
        protected string $baseUrl,
        protected string $key,
        protected int $timeout = 20,
    ) {
        $this->baseUrl = rtrim($this->baseUrl, '/');
    }

    public static function fromConfig(): ?static
    {
        $cfg = (array) config('talivio.infra.mail_gateway', []);

        if (blank($cfg['base_url'] ?? null) || blank($cfg['key'] ?? null)) {
            return null;
        }

        return new static(
            baseUrl: (string) $cfg['base_url'],
            key: (string) $cfg['key'],
            timeout: (int) ($cfg['timeout'] ?? 20),
        );
    }

    /** @return list<string> */
    public static function requiredEnv(): array
    {
        return ['TALIVIO_MAIL_URL', 'TALIVIO_MAIL_KEY'];
    }

    public function domains(?string $customerRef = null): array
    {
        return $this->data($this->get('/domains', $customerRef === null ? [] : ['customer_ref' => $customerRef]));
    }

    public function createDomain(string $customerRef, string $domain, array $limits = []): array
    {
        return $this->data($this->post('/domains', array_filter([
            'customer_ref' => $customerRef,
            'domain' => strtolower(trim($domain)),
            'label' => $limits['label'] ?? null,
            'mailboxes' => $limits['mailboxes'] ?? null,
            'quota_mb' => $limits['quota_mb'] ?? null,
            'total_quota_mb' => $limits['total_quota_mb'] ?? null,
            'aliases' => $limits['aliases'] ?? null,
        ], fn ($value) => $value !== null)));
    }

    public function dnsRecords(string $domain): array
    {
        return $this->data($this->get('/domains/'.$this->segment($domain).'/dns'));
    }

    public function verifyDomain(string $domain): array
    {
        return $this->data($this->post('/domains/'.$this->segment($domain).'/verify'));
    }

    public function deleteDomain(string $domain): void
    {
        $this->delete('/domains/'.$this->segment($domain));
    }

    public function createMailbox(string $domain, string $localPart, string $password, string $name = '', ?int $quotaMb = null): array
    {
        return $this->data($this->post('/mailboxes', array_filter([
            'domain' => strtolower(trim($domain)),
            'local_part' => strtolower(trim($localPart)),
            'password' => $password,
            'name' => $name !== '' ? $name : null,
            'quota_mb' => $quotaMb,
        ], fn ($value) => $value !== null)));
    }

    public function deleteMailbox(string $address): void
    {
        $this->delete('/mailboxes/'.$this->segment($address));
    }

    public function createAlias(string $address, string $goto): void
    {
        $this->post('/aliases', ['address' => strtolower(trim($address)), 'goto' => $goto]);
    }

    public function deleteAlias(string $address): void
    {
        $this->delete('/aliases/'.$this->segment($address));
    }

    public function usage(?string $customerRef = null): array
    {
        return $this->data($this->get('/usage', $customerRef === null ? [] : ['customer_ref' => $customerRef]));
    }

    // ------------------------------------------------------------------

    /**
     * An address or domain going into the PATH. Encoded because an alias
     * address contains "@" and could otherwise be read as userinfo.
     */
    protected function segment(string $value): string
    {
        return rawurlencode(strtolower(trim($value)));
    }

    /**
     * @param  array<string, mixed>  $query
     */
    protected function get(string $path, array $query = []): Response
    {
        return $this->send(fn (PendingRequest $http) => $http->get($this->baseUrl.$path, $query), retry: true);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function post(string $path, array $payload = []): Response
    {
        return $this->send(fn (PendingRequest $http) => $http->post($this->baseUrl.$path, $payload), retry: false);
    }

    protected function delete(string $path): Response
    {
        return $this->send(fn (PendingRequest $http) => $http->delete($this->baseUrl.$path), retry: false);
    }

    /**
     * @param  callable(PendingRequest): Response  $call
     */
    protected function send(callable $call, bool $retry): Response
    {
        $request = Http::withToken($this->key)->acceptJson()->asJson()->timeout($this->timeout);

        try {
            $response = $call($retry ? $this->retrying($request) : $request);
        } catch (ConnectionException $e) {
            throw new RuntimeException('Mailio is unreachable: '.$e->getMessage(), previous: $e);
        }

        if ($response->successful()) {
            return $response;
        }

        // 4xx is Mailio saying no, and its wording is meant for the
        // customer. 5xx (and an unparseable body) is an outage.
        if ($response->clientError()) {
            $reason = (string) ($response->json('error') ?? $response->json('message') ?? '');

            throw HostRefusedException::withReason('Mailio', $reason !== '' ? $reason : "HTTP {$response->status()}");
        }

        throw new RuntimeException("Mailio failed with HTTP {$response->status()}.");
    }

    /**
     * @return array<mixed>
     */
    protected function data(Response $response): array
    {
        return (array) ($response->json('data') ?? []);
    }
}
