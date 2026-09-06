<?php

namespace Talivio\Sdk\Infra\Testing;

use RuntimeException;
use Talivio\Sdk\Infra\Contracts\Host;

/**
 * In-memory Host for product tests — bind it to the contract and nothing
 * reaches Ploi. Records what was asked of it; tests flip the public knobs
 * to simulate a certificate that's still being issued, a host rejecting
 * the request (DNS not pointing here yet), or an outage.
 */
class FakeHost implements Host
{
    public string $ip = '203.0.113.10';

    /** @var list<string> */
    public array $attached = [];

    /** @var list<string> */
    public array $detached = [];

    /** @var array<string, array{domains: list<string>, webhook: ?string, via_dns: bool}> keyed by domain, last request wins */
    public array $certificateRequests = [];

    /** @var array<string, int> how many times a certificate was requested per domain */
    public array $certificateRequestCount = [];

    /** @var list<string> domains whose certificate reports as installed on the platform site */
    public array $issued = [];

    /** New certificate requests are issued immediately (the fast path). */
    public bool $issueOnRequest = true;

    /** Set to a message to make requestCertificate() throw (host pre-check failing). */
    public ?string $rejectCertificateWith = null;

    /** Set to a message to make every call throw. */
    public ?string $failWith = null;

    /** @var list<array{id: int, domain: string, aliases: list<string>, status: ?string, project_type: ?string, php_version: ?string, system_user: ?string, last_deploy_at: ?string, disk_usage_mb: ?int, has_repository: bool, created_at: ?string}> */
    public array $sites = [];

    /** @var list<int> */
    public array $deletedSites = [];

    /** @var array<int, array<string, mixed>> options createSite() was called with, keyed by the new site id */
    public array $siteOptions = [];

    /** @var array<int, array{domains: list<string>, webhook: ?string}> keyed by site id, last request wins */
    public array $siteCertificateRequests = [];

    /** @var array<int, list<string>> domains with an issued certificate per site */
    public array $siteIssued = [];

    /** ISO-8601 expiry reported for every fake certificate; null = pending. */
    public ?string $certificateExpiresAt = '2026-12-31T00:00:00.000000Z';

    protected int $nextSiteId = 500;

    public function serverIp(): string
    {
        $this->guard();

        return $this->ip;
    }

    public function attachDomain(string $domain): void
    {
        $this->guard();

        $domain = strtolower(trim($domain));

        if (! in_array($domain, $this->attached, true)) {
            $this->attached[] = $domain;
        }
    }

    public function requestCertificate(string $domain, array $domains = [], ?string $webhookUrl = null, bool $validateViaDns = false): void
    {
        $this->guard();

        if ($this->rejectCertificateWith !== null) {
            throw new RuntimeException($this->rejectCertificateWith);
        }

        $domain = strtolower(trim($domain));
        $this->certificateRequests[$domain] = ['domains' => array_values($domains), 'webhook' => $webhookUrl, 'via_dns' => $validateViaDns];
        $this->certificateRequestCount[$domain] = ($this->certificateRequestCount[$domain] ?? 0) + 1;

        if ($this->issueOnRequest && ! in_array($domain, $this->issued, true)) {
            $this->issued[] = $domain;
        }
    }

    public function certificateIssued(string $domain): bool
    {
        $this->guard();

        return in_array(strtolower(trim($domain)), $this->issued, true);
    }

    public function detachDomain(string $domain): void
    {
        $this->guard();

        $domain = strtolower(trim($domain));
        $this->detached[] = $domain;
        $this->attached = array_values(array_diff($this->attached, [$domain]));
        $this->issued = array_values(array_diff($this->issued, [$domain]));
    }

    public function listSites(): array
    {
        $this->guard();

        return array_values($this->sites);
    }

    public function createSite(string $domain, array $options = []): array
    {
        $this->guard();

        $domain = strtolower(trim($domain));

        foreach ($this->sites as $site) {
            if ($site['domain'] === $domain) {
                throw new RuntimeException("Ploi site creation failed: a site for {$domain} already exists.");
            }
        }

        $id = $this->nextSiteId++;
        $this->sites[] = [
            'id' => $id,
            'domain' => $domain,
            'aliases' => [],
            'status' => 'active',
            'project_type' => $options['project_type'] ?? 'laravel',
            'php_version' => isset($options['php_version']) ? (string) $options['php_version'] : '8.4',
            'system_user' => $options['system_user'] ?? 'ploi',
            'last_deploy_at' => null,
            'disk_usage_mb' => 0,
            'has_repository' => false,
            'created_at' => '2026-01-01 00:00:00',
        ];
        $this->siteOptions[$id] = $options;

        return ['id' => $id, 'domain' => $domain];
    }

    public function deleteSite(int|string $siteId): void
    {
        $this->guard();

        $this->deletedSites[] = (int) $siteId;
        $this->sites = array_values(array_filter($this->sites, fn ($site) => $site['id'] !== (int) $siteId));
    }

    public function requestSiteCertificate(int|string $siteId, array $domains, ?string $webhookUrl = null): void
    {
        $this->guard();

        if ($this->rejectCertificateWith !== null) {
            throw new RuntimeException($this->rejectCertificateWith);
        }

        $domains = array_values(array_map(fn ($d) => strtolower(trim((string) $d)), $domains));
        $this->siteCertificateRequests[(int) $siteId] = ['domains' => $domains, 'webhook' => $webhookUrl];

        if ($this->issueOnRequest) {
            $this->siteIssued[(int) $siteId] = array_values(array_unique([...($this->siteIssued[(int) $siteId] ?? []), ...$domains]));
        }
    }

    public function siteCertificateIssued(int|string $siteId, string $domain): bool
    {
        $this->guard();

        return in_array(strtolower(trim($domain)), $this->siteIssued[(int) $siteId] ?? [], true);
    }

    public function siteCertificates(int|string $siteId): array
    {
        $this->guard();

        $siteId = (int) $siteId;
        $request = $this->siteCertificateRequests[$siteId] ?? null;

        if ($request === null) {
            return [];
        }

        $domains = $request['domains'];
        $status = in_array($domains[0] ?? null, $this->siteIssued[$siteId] ?? [], true) ? 'active' : 'pending';

        return [[
            'id' => 9000 + $siteId,
            'domains' => $domains,
            'status' => $status,
            'type' => 'letsencrypt',
            'expires_at' => $status === 'active' ? $this->certificateExpiresAt : null,
        ]];
    }

    protected function guard(): void
    {
        if ($this->failWith !== null) {
            throw new RuntimeException($this->failWith);
        }
    }
}
