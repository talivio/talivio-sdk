<?php

namespace Talivio\Sdk\Infra\Contracts;

use RuntimeException;

/**
 * Authoritative DNS for domains Talivio runs on a customer's behalf
 * (`talivio.infra.dns` — Cloudflare). The Registrar is told to delegate
 * the domain to the zone's nameservers; the zone's records point the
 * domain at the platform's web server (Host::serverIp()).
 *
 * Two ways a domain ends up here:
 *  - Talivio registered (or transferred in) the domain → a fresh zone,
 *    nothing to import.
 *  - The customer owns the domain and delegates its NS to us ("NS
 *    delegation") → ensureZone(..., jumpStart: true) so the DNS it already
 *    has (email!) survives the handover.
 *
 * A customer who keeps their own DNS never touches this: they prove
 * ownership with a CNAME + TXT and the product's DomainVerifier checks it.
 * The one exception is Contentio's "customer hands us a zone-scoped
 * Cloudflare token" path — that builds a client with the CUSTOMER's
 * credentials (Cloudflare::__construct) and only ever calls findZoneId()
 * + upsertRecord() on it.
 */
interface Dns
{
    /**
     * Finds or creates the zone for $domain. Idempotent, so a registration
     * job that failed after creating the zone can simply run again.
     *
     * $jumpStart asks the provider to scan the domain's CURRENT DNS (at
     * whatever nameservers it resolves through today) and import what it
     * finds into the new zone — the domain already has real records
     * (MX/SPF/DKIM for existing email, other subdomains) that would
     * otherwise silently stop working the moment the customer delegates
     * NS to us. False (the default) for a domain with nothing to
     * import — a fresh Talivio registration or an incoming registrar
     * transfer, where jump_start would just import parking-page noise.
     *
     * @return array{id: string, nameservers: list<string>, active: bool}
     *                                                                    nameservers are what the registrar must delegate to; active is
     *                                                                    whether that delegation is already live at the provider.
     *
     * @throws RuntimeException on any provider-side failure
     */
    public function ensureZone(string $domain, bool $jumpStart = false): array;

    /**
     * Whether the registrar's delegation has reached the provider — i.e.
     * the world now resolves this domain through the zone. Until it does,
     * nothing served from the zone (including a Let's Encrypt HTTP
     * challenge) is reachable.
     *
     * @throws RuntimeException on any provider-side failure
     */
    public function zoneIsActive(string $zoneId): bool;

    /**
     * Id of the ACTIVE zone whose name is the longest suffix of $domain
     * (`www.example.com` → the `example.com` zone), or null when the
     * credential can't see one. This is how a hostname is matched to a
     * zone the caller didn't create — e.g. a customer's own zone reached
     * through their token.
     *
     * @throws RuntimeException on any provider-side failure
     */
    public function findZoneId(string $domain): ?string;

    /**
     * Upserts the records that make a site reachable: the apex pointing at
     * the platform's server and www aliasing the apex. Idempotent.
     *
     * @throws RuntimeException on any provider-side failure
     */
    public function ensureRecords(string $zoneId, string $domain, string $ipv4): void;

    /**
     * Upserts ONE record, matched on type + name: an existing record is
     * updated in place, never duplicated (the provider would otherwise
     * happily create a second identical CNAME, which is invalid DNS).
     * Used for everything ensureRecords() doesn't cover — the CNAME+TXT
     * ownership proof, MX/SPF/DKIM for hosted mail.
     *
     * $proxied null = leave the provider's default (Cloudflare: DNS-only /
     * grey cloud). ⚠️ A proxied CNAME hides its target from public DNS,
     * so an ownership-proof CNAME must be explicitly false.
     *
     * @throws RuntimeException on any provider-side failure
     */
    public function upsertRecord(string $zoneId, string $type, string $name, string $content, ?bool $proxied = null, ?int $priority = null): void;

    /**
     * Every record in the zone, optionally only those whose name contains
     * $nameContains. Follows pagination.
     *
     * @return list<array<string, mixed>> records as the provider returns them
     *
     * @throws RuntimeException on any provider-side failure
     */
    public function listRecords(string $zoneId, ?string $nameContains = null): array;

    /**
     * Removes the zone (a tenant being purged hands its domains back).
     * Silently succeeds if it's already gone.
     *
     * @throws RuntimeException on any other provider-side failure
     */
    public function deleteZone(string $zoneId): void;

    /**
     * Whether the credential this client was built with is accepted by the
     * provider — the pre-flight before writing to a zone with a token the
     * customer typed in.
     */
    public function verifyCredentials(): bool;
}
