<?php

namespace Talivio\Sdk\Infra\Testing;

use Talivio\Sdk\Infra\Contracts\DnsProbe;

/**
 * In-memory public DNS. Bind it and no test touches a resolver.
 *
 * Hosts are matched case-insensitively and with any trailing dot removed,
 * because that is how a real answer comes back and a test that only passes
 * for the exact string it typed is testing itself.
 */
class FakeDnsProbe implements DnsProbe
{
    /** @var array<string, list<string>> */
    public array $a = [];

    /** @var array<string, list<string>> */
    public array $cname = [];

    /** @var array<string, list<string>> */
    public array $txt = [];

    /**
     * Publishes an ownership token where DomainOwnership will look for it,
     * so a test does not have to know the `_talivio-verify.` convention.
     */
    public function publishToken(string $domain, string $token, string $prefix = '_talivio-verify.'): static
    {
        return $this->withTxt($prefix.$domain, [$token]);
    }

    /** @param list<string> $ips */
    public function withA(string $host, array $ips): static
    {
        $this->a[$this->key($host)] = $ips;

        return $this;
    }

    /** @param list<string> $targets */
    public function withCname(string $host, array $targets): static
    {
        $this->cname[$this->key($host)] = $targets;

        return $this;
    }

    /** @param list<string> $values */
    public function withTxt(string $host, array $values): static
    {
        $this->txt[$this->key($host)] = $values;

        return $this;
    }

    public function a(string $host): array
    {
        return $this->a[$this->key($host)] ?? [];
    }

    public function cnameTargets(string $host): array
    {
        return array_map(
            fn (string $target) => rtrim($target, '.'),
            $this->cname[$this->key($host)] ?? [],
        );
    }

    public function txtRecords(string $host): array
    {
        return $this->txt[$this->key($host)] ?? [];
    }

    protected function key(string $host): string
    {
        return strtolower(rtrim(trim($host), '.'));
    }
}
