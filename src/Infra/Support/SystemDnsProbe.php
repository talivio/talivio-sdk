<?php

namespace Talivio\Sdk\Infra\Support;

use Talivio\Sdk\Infra\Contracts\DnsProbe;

/**
 * `DnsProbe` over PHP's own resolver (`dns_get_record`), which is what
 * every product was already doing in its own copy of this file.
 *
 * The `@` is deliberate and load-bearing: `dns_get_record()` emits a
 * warning and returns false for NXDOMAIN, SERVFAIL and timeouts alike.
 * Products call this from customer-facing pages, and a resolver hiccup
 * must read as "not published yet", never as a 500 or a log full of
 * warnings for domains that simply are not set up.
 */
class SystemDnsProbe implements DnsProbe
{
    public function a(string $host): array
    {
        return $this->values($this->lookup($host, DNS_A), 'ip');
    }

    public function cnameTargets(string $host): array
    {
        return array_map(
            fn (string $target) => rtrim($target, '.'),
            $this->values($this->lookup($host, DNS_CNAME), 'target'),
        );
    }

    public function txtRecords(string $host): array
    {
        return $this->values($this->lookup($host, DNS_TXT), 'txt');
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function lookup(string $host, int $type): array
    {
        $records = @dns_get_record(strtolower(trim($host)), $type);

        return is_array($records) ? $records : [];
    }

    /**
     * @param  list<array<string, mixed>>  $records
     * @return list<string>
     */
    protected function values(array $records, string $key): array
    {
        $values = array_map(fn (array $record) => (string) ($record[$key] ?? ''), $records);

        return array_values(array_filter($values, fn (string $value) => $value !== ''));
    }
}
