<?php

namespace Talivio\Sdk\Infra\Contracts;

use RuntimeException;

/**
 * The web server that actually answers for a customer's domain
 * (`talivio.infra.host` — Ploi). Every Talivio product is ONE site on ONE
 * Ploi-managed server; a customer domain is attached to that site (so
 * nginx answers for it) and gets a TLS certificate before the product is
 * reachable over https. The five "platform site" methods below are what
 * a product's provisioning job needs.
 *
 * The "sites" methods at the bottom are for OPERATIONS work (talivio.com's
 * admin Hosting page): a service customer whose site Talivio hosts as a
 * SEPARATE Ploi site, not a tenant of a product. Products never call
 * them.
 *
 * ⚠️ Ploi tokens carry an IP allowlist. A 403 "This IP address is not
 * allowed" is NOT a scope problem — the calling host must be added in
 * Ploi → Profile → API tokens. Every Talivio product runs on the same
 * server, so one allowlist entry covers all of them. Ploi also
 * rate-limits tokens (~60 req/min) — nothing here should poll tightly.
 */
interface Host
{
    /**
     * The public IPv4 the platform site is served from — what a domain's
     * DNS gets pointed at (Dns::ensureRecords()).
     *
     * @throws RuntimeException on any host-side failure
     */
    public function serverIp(): string;

    /**
     * Makes the platform site answer for $domain. Idempotent — attaching a
     * domain that's already attached is not an error.
     *
     * @throws RuntimeException on any host-side failure
     */
    public function attachDomain(string $domain): void;

    /**
     * Asks the host to obtain a certificate covering $domain plus
     * $domains (www, where its DNS is ours). Asynchronous at the host:
     * this only confirms the request was accepted. The host POSTs
     * $webhookUrl once the certificate is installed, and
     * certificateIssued() is the polling fallback. Idempotent where the
     * host lets us tell (a certificate already covering the domain is
     * not requested twice).
     *
     * $validateViaDns asks for a DNS-01 challenge through the Dns
     * provider's credential (only possible for a domain whose zone we
     * run) instead of HTTP-01. HTTP-01 for a not-yet-certified hostname
     * depends on the server's catch-all vhost serving the challenge
     * file, which a shared server may not do; DNS-01 doesn't touch the
     * web server at all.
     *
     * @param  list<string>  $domains
     *
     * @throws RuntimeException if the request is rejected outright — most
     *                          commonly because $domain doesn't resolve to
     *                          serverIp() yet
     */
    public function requestCertificate(string $domain, array $domains = [], ?string $webhookUrl = null, bool $validateViaDns = false): void;

    /**
     * Whether an active certificate covering $domain is installed on the
     * platform site.
     *
     * @throws RuntimeException on any host-side failure
     */
    public function certificateIssued(string $domain): bool;

    /**
     * Stops the platform site answering for $domain and drops its
     * certificate. Silently succeeds if it was never attached.
     *
     * @throws RuntimeException on any other host-side failure
     */
    public function detachDomain(string $domain): void;

    /**
     * Every site on the server, following pagination (the shared server
     * carries 70+ sites — an unpaginated read silently misses most). The
     * fields beyond id/domain/aliases are for an OPERATIONS inventory;
     * products must only rely on the first three.
     *
     * @return list<array{id: int, domain: string, aliases: list<string>, status: ?string, project_type: ?string, php_version: ?string, system_user: ?string, last_deploy_at: ?string, disk_usage_mb: ?int, has_repository: bool, created_at: ?string}>
     *
     * @throws RuntimeException on any host-side failure
     */
    public function listSites(): array;

    /**
     * Creates a NEW site on the server for $domain. Returns the new
     * site's id and domain. $options are passed through to the host
     * (Ploi: web_directory, project_type, project_root, system_user, …)
     * on top of sensible defaults. NOT idempotent — a second call for a
     * domain that already has a site is a host-side error.
     *
     * @param  array<string, mixed>  $options
     * @return array{id: int, domain: string}
     *
     * @throws RuntimeException on any host-side failure
     */
    public function createSite(string $domain, array $options = []): array;

    /**
     * Deletes a site — its vhost, files and certificates. Irreversible;
     * callers confirm with a human first. Silently succeeds if the site
     * is already gone.
     *
     * @throws RuntimeException on any other host-side failure
     */
    public function deleteSite(int|string $siteId): void;

    /**
     * Requests a certificate on an arbitrary site (one created with
     * createSite()), as opposed to the platform site's own domains.
     * Idempotent: a certificate already covering the first domain is
     * not requested twice.
     *
     * @param  list<string>  $domains
     *
     * @throws RuntimeException on any host-side failure
     */
    public function requestSiteCertificate(int|string $siteId, array $domains, ?string $webhookUrl = null): void;

    /**
     * Whether an active certificate covering $domain is installed on the
     * given site.
     *
     * @throws RuntimeException on any host-side failure
     */
    public function siteCertificateIssued(int|string $siteId, string $domain): bool;

    /**
     * Every certificate on a site (created with createSite()) — what an
     * operations inventory needs to say "expires in 12 days" for 70+ sites.
     * `domains` are the hostnames the certificate covers, `status` is the
     * host's own word (Ploi: active / pending / failed), `expires_at` an
     * ISO-8601 string or null while pending.
     *
     * @return list<array{id: int, domains: list<string>, status: string, type: string, expires_at: ?string}>
     *
     * @throws RuntimeException on any host-side failure
     */
    public function siteCertificates(int|string $siteId): array;
}
