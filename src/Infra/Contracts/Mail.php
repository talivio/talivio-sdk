<?php

namespace Talivio\Sdk\Infra\Contracts;

use RuntimeException;

/**
 * Hosted mail for customer domains (`talivio.infra.mail` — mailcow). ONE
 * shared mailcow instance hosts mailboxes and forwarding aliases for
 * every customer domain that turns email on; which of the two a customer
 * gets is the product's business (Contentio tiers it by plan).
 *
 * The mail host only stores mail. For the world to deliver to it, the
 * domain's DNS needs MX/SPF/DKIM — dnsRecords() says exactly which, and
 * a product writes them through Dns::upsertRecord() when the zone is
 * ours, or shows them to the customer when it isn't.
 *
 * ⚠️ mailcow's API has an IP allowlist too ("Allow from" under
 * Configuration → Access → API) — same trap as Ploi/Namecheap/Cloudflare.
 */
interface Mail
{
    /**
     * Makes the mail host accept $domain. Idempotent — the host no-ops
     * on a domain it already has.
     *
     * @throws RuntimeException on any host-side failure
     */
    public function addDomain(string $domain, int $maxMailboxes = 10, int $maxQuotaMb = 10240): void;

    /**
     * The domain's DKIM public key as a DNS TXT record. The host
     * generates the keypair the first time one is asked for; the
     * selector is normally "dkim" but not guaranteed, so it's read back
     * rather than assumed. Null when the host has no key (yet) for the
     * domain.
     *
     * @return array{selector: string, record: string}|null
     *
     * @throws RuntimeException on any host-side failure
     */
    public function dkim(string $domain): ?array;

    /**
     * Every DNS record the domain needs for mail to flow: MX to the mail
     * host, the SPF TXT, and the DKIM TXT (when the host has a key).
     * Names are fully qualified. Empty entries (an unconfigured MX host)
     * are left out rather than emitted blank.
     *
     * @return list<array{type: string, name: string, content: string, priority?: int}>
     *
     * @throws RuntimeException on any host-side failure
     */
    public function dnsRecords(string $domain): array;

    /**
     * A real mailbox with storage, reachable over IMAP/SMTP/webmail.
     *
     * @throws RuntimeException on any host-side failure, including a
     *                          password the host's policy rejects
     */
    public function addMailbox(string $domain, string $localPart, string $password, string $name = ''): void;

    /**
     * @throws RuntimeException on any host-side failure
     */
    public function deleteMailbox(string $email): void;

    /**
     * @return list<array<string, mixed>> mailboxes as the host returns them
     *
     * @throws RuntimeException on any host-side failure
     */
    public function listMailboxes(string $domain): array;

    /**
     * Pure forward — mail to $address is relayed straight to $goto (an
     * external address the customer already owns), no storage involved.
     *
     * @throws RuntimeException on any host-side failure
     */
    public function addAlias(string $address, string $goto): void;

    /**
     * @throws RuntimeException on any host-side failure
     */
    public function deleteAlias(string $address): void;

    /**
     * @return list<array<string, mixed>> aliases as the host returns them
     *
     * @throws RuntimeException on any host-side failure
     */
    public function listAliases(string $domain): array;
}
