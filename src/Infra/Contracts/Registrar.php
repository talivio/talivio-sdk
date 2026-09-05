<?php

namespace Talivio\Sdk\Infra\Contracts;

use RuntimeException;
use Talivio\Sdk\Infra\Exceptions\AuthCodeUnavailableException;

/**
 * Talivio's reseller-side view of a domain registrar. ONE platform-wide
 * account registers domains on behalf of every product's customers
 * (`talivio.infra.registrar` picks the driver — see
 * TalivioServiceProvider::registerInfra()).
 *
 * Every post-registration operation receives BOTH the domain name and the
 * registrar's own id (stored by the product, e.g. StoreDomain::
 * registrar_domain_id) because registrars disagree on what they key
 * operations by: Openprovider by its numeric domain id, Namecheap by the
 * domain name itself (its DomainID is informational only). Passing both
 * keeps the callers driver-agnostic.
 *
 * ⚠️ IP allowlists: Namecheap sends a ClientIp with every call and rejects
 * one that is not whitelisted on the account (error 1011102/1011150)
 * before looking at anything else. All Talivio products run on the same
 * server, so the outbound IP — and therefore the allowlist — is shared.
 */
interface Registrar
{
    /**
     * @return array{available: bool, premium: bool, price: int, currency: string} price
     *                                                                             is the first-year cost in minor units, AFTER Talivio's margin
     *                                                                             (`talivio.infra.domains.margin_percent`). Premium names are reported
     *                                                                             as unavailable: they carry their own registration AND renewal price,
     *                                                                             which the flat per-TLD quote the products reuse for renewals can't
     *                                                                             honour.
     */
    public function checkAvailability(string $domain): array;

    /**
     * Registers the domain for one year, delegated to $nameservers (the
     * Dns zone's — see Dns::ensureZone()). An EMPTY list leaves the domain
     * on the registrar's own default nameservers: use it when the zone
     * can only be created after registration (Cloudflare refuses a zone
     * for an unregistered name) and follow up with configureNameservers().
     * Returns the registrar's own id for the domain, to be stored for
     * future renewal/lookup calls.
     *
     * @param  array{name: string, email: string, phone: string, address: string, city: string, postal_code: string, country: string, state?: string}  $registrant
     *                                                                                                                                                              phone is international ("+CC..." — separators tolerated), country is ISO 3166-1 alpha-2.
     * @param  list<string>  $nameservers
     *
     * @throws RuntimeException on any registrar-side failure
     */
    public function register(string $domain, array $registrant, array $nameservers): string;

    /**
     * Extends an already-registered domain's expiry by $years.
     *
     * @throws RuntimeException on any registrar-side failure
     */
    public function renew(string $domain, string $registrarDomainId, int $years = 1): void;

    /**
     * Submits a transfer-IN request for a domain registered elsewhere.
     * Transfers are asynchronous at the registry (ICANN allows up to 5
     * days) — this only confirms the request was accepted for processing,
     * never that the domain is actually controlled yet. Returns the
     * registrar's own id for the TRANSFER (what transferStatus() is later
     * polled with).
     *
     * @param  array{name: string, email: string, phone: string, address: string, city: string, postal_code: string, country: string, state?: string}  $registrant
     *
     * @throws RuntimeException if the request itself is rejected outright
     *                          (invalid auth code format, unsupported TLD,
     *                          etc.) — a rejection later in the transfer
     *                          process is reported by transferStatus().
     */
    public function transferIn(string $domain, string $authCode, array $registrant): string;

    /**
     * @return string one of 'pending', 'completed', 'failed'
     *
     * @throws RuntimeException on any registrar-side failure
     */
    public function transferStatus(string $registrarDomainId): string;

    /**
     * Delegates the domain to $nameservers. register() already does this
     * when given a list; this exists for a transferred-in domain (which
     * arrives still carrying the losing registrar's nameservers — a
     * transfer request can't set them up front at Namecheap) and for a
     * domain registered before its zone existed. Idempotent.
     *
     * @param  list<string>  $nameservers
     *
     * @throws RuntimeException on any registrar-side failure
     */
    public function configureNameservers(string $domain, string $registrarDomainId, array $nameservers): void;

    /**
     * The EPP/auth code needed to transfer this domain OUT to another
     * registrar. Never persisted by any product (same GDPR-conscious
     * posture as WHOIS privacy) — fetched fresh each time it's shown.
     *
     * @throws AuthCodeUnavailableException when the registrar simply doesn't
     *                                      hand out auth codes over its API
     *                                      (Namecheap) — the domain is fine,
     *                                      the code just has to be requested
     *                                      out of band
     * @throws RuntimeException on any other registrar-side failure, including
     *                          a domain that's still locked (see
     *                          setTransferLock())
     */
    public function getAuthCode(string $domain, string $registrarDomainId): string;

    /**
     * ICANN requires a domain unlocked before it can transfer OUT — this
     * toggles that lock at the registrar.
     *
     * @throws RuntimeException on any registrar-side failure
     */
    public function setTransferLock(string $domain, string $registrarDomainId, bool $locked): void;
}
