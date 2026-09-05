<?php

namespace Talivio\Sdk\Infra\Testing;

use RuntimeException;
use Talivio\Sdk\Infra\Contracts\Dns;

/**
 * In-memory Dns for product tests — bind it to the contract and nothing
 * reaches Cloudflare. Records what was asked of it; tests flip the public
 * knobs to simulate a zone whose delegation hasn't propagated yet or a
 * provider outage.
 */
class FakeDns implements Dns
{
    /** @var array<string, array{id: string, nameservers: list<string>, active: bool}> keyed by domain */
    public array $zones = [];

    /** @var list<string> domains ensureZone() was called for with jumpStart */
    public array $jumpStarted = [];

    /** @var array<string, list<array{type: string, name: string, content: string, proxied: ?bool, priority: ?int}>> keyed by zone id */
    public array $records = [];

    /** @var list<string> */
    public array $deletedZones = [];

    /** Zones created from now on report inactive delegation. */
    public bool $newZonesActive = true;

    /** What verifyCredentials() answers. */
    public bool $credentialsValid = true;

    /** Set to a message to make every call throw. */
    public ?string $failWith = null;

    /** Set to a message to make only record writes throw. */
    public ?string $failRecordsWith = null;

    /** @var list<string> */
    public array $nameservers = ['ada.ns.cloudflare.com', 'bob.ns.cloudflare.com'];

    public function ensureZone(string $domain, bool $jumpStart = false): array
    {
        $this->guard();

        $domain = strtolower(trim($domain));

        if ($jumpStart && ! in_array($domain, $this->jumpStarted, true)) {
            $this->jumpStarted[] = $domain;
        }

        return $this->zones[$domain] ??= [
            'id' => 'zone_'.substr(md5($domain), 0, 8),
            'nameservers' => $this->nameservers,
            'active' => $this->newZonesActive,
        ];
    }

    public function zoneIsActive(string $zoneId): bool
    {
        $this->guard();

        foreach ($this->zones as $zone) {
            if ($zone['id'] === $zoneId) {
                return $zone['active'];
            }
        }

        return false;
    }

    public function findZoneId(string $domain): ?string
    {
        $this->guard();

        $labels = explode('.', strtolower(trim($domain)));

        for ($i = 0; $i < count($labels) - 1; $i++) {
            $candidate = implode('.', array_slice($labels, $i));

            if (isset($this->zones[$candidate]) && $this->zones[$candidate]['active']) {
                return $this->zones[$candidate]['id'];
            }
        }

        return null;
    }

    /** Test helper: flips a zone to active, as if the registrar's delegation landed. */
    public function activate(string $domain): void
    {
        $this->zones[strtolower(trim($domain))]['active'] = true;
    }

    public function ensureRecords(string $zoneId, string $domain, string $ipv4): void
    {
        $domain = strtolower(trim($domain));

        $this->upsertRecord($zoneId, 'A', $domain, $ipv4, false);
        $this->upsertRecord($zoneId, 'CNAME', "www.{$domain}", $domain, false);
    }

    public function upsertRecord(string $zoneId, string $type, string $name, string $content, ?bool $proxied = null, ?int $priority = null): void
    {
        $this->guard();

        if ($this->failRecordsWith !== null) {
            throw new RuntimeException($this->failRecordsWith);
        }

        $type = strtoupper($type);
        $name = strtolower(trim($name));

        foreach ($this->records[$zoneId] ?? [] as $index => $record) {
            if ($record['type'] === $type && $record['name'] === $name) {
                $this->records[$zoneId][$index] = compact('type', 'name', 'content', 'proxied', 'priority');

                return;
            }
        }

        $this->records[$zoneId][] = compact('type', 'name', 'content', 'proxied', 'priority');
    }

    public function listRecords(string $zoneId, ?string $nameContains = null): array
    {
        $this->guard();

        return array_values(array_filter(
            $this->records[$zoneId] ?? [],
            fn ($record) => $nameContains === null || str_contains($record['name'], strtolower($nameContains)),
        ));
    }

    public function deleteZone(string $zoneId): void
    {
        $this->guard();

        $this->deletedZones[] = $zoneId;
    }

    public function verifyCredentials(): bool
    {
        $this->guard();

        return $this->credentialsValid;
    }

    /** Test helper: the record of the given type + name in a zone, if any. */
    public function record(string $zoneId, string $type, string $name): ?array
    {
        foreach ($this->records[$zoneId] ?? [] as $record) {
            if ($record['type'] === strtoupper($type) && $record['name'] === strtolower(trim($name))) {
                return $record;
            }
        }

        return null;
    }

    protected function guard(): void
    {
        if ($this->failWith !== null) {
            throw new RuntimeException($this->failWith);
        }
    }
}
