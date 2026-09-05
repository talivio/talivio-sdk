<?php

namespace Talivio\Sdk\Infra\Support;

use Talivio\Sdk\Infra\Contracts\Dns;
use Talivio\Sdk\Infra\Exceptions\NotConfiguredException;

/**
 * Dns contract stand-in for an environment without DNS credentials —
 * resolvable, every call throws NotConfiguredException. See
 * UnconfiguredRegistrar for why resolution must not fail.
 */
final class UnconfiguredDns implements Dns
{
    /**
     * @param  list<string>  $envKeys
     */
    public function __construct(private string $service, private array $envKeys) {}

    public function ensureZone(string $domain, bool $jumpStart = false): array
    {
        throw $this->exception();
    }

    public function zoneIsActive(string $zoneId): bool
    {
        throw $this->exception();
    }

    public function findZoneId(string $domain): ?string
    {
        throw $this->exception();
    }

    public function ensureRecords(string $zoneId, string $domain, string $ipv4): void
    {
        throw $this->exception();
    }

    public function upsertRecord(string $zoneId, string $type, string $name, string $content, ?bool $proxied = null, ?int $priority = null): void
    {
        throw $this->exception();
    }

    public function listRecords(string $zoneId, ?string $nameContains = null): array
    {
        throw $this->exception();
    }

    public function deleteZone(string $zoneId): void
    {
        throw $this->exception();
    }

    public function verifyCredentials(): bool
    {
        return false;
    }

    private function exception(): NotConfiguredException
    {
        return NotConfiguredException::forService($this->service, $this->envKeys);
    }
}
