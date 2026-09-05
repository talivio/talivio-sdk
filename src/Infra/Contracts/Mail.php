<?php

namespace Talivio\Sdk\Infra\Contracts;

use RuntimeException;
use Talivio\Sdk\Infra\Support\MailOwner;

/**
 * Hosted mail for customer domains (`talivio.infra.mail` — mailcow). ONE
 * shared mailcow instance hosts mailboxes and forwarding aliases for
 * every customer domain across every Talivio product; which tier a
 * customer gets is the product's business.
 *
 * The surface splits in two, and the split is the whole point:
 *
 *  - **What any product needs** — addDomain/dkim/dnsRecords/addMailbox/
 *    addAlias and their delete+list pairs. A storefront or CMS turns mail
 *    on for a domain it already owns and creates a handful of addresses.
 *  - **What the owner-of-record needs** — everything else: listing and
 *    editing domains, toggling a domain active, editing or bulk-suspending
 *    mailboxes, reading quota, aggregating usage, sync jobs. Mailio is
 *    that owner (2026-09-05 decision: every mail package Talivio sells,
 *    in any product, is a Mailio package). Other products may call these,
 *    but if two products both start editing the same domain the instance
 *    has no arbiter — route it through Mailio instead.
 *
 * The mail host only stores mail. For the world to deliver to it, the
 * domain's DNS needs MX/SPF/DMARC/DKIM — dnsRecords() says exactly which,
 * and a product writes them through Dns::upsertRecord() when the zone is
 * ours, or shows them to the customer when it isn't.
 *
 * ⚠️ Every write throws on failure. mailcow answers HTTP 200 even for a
 * refused write (the error is in the body), so "no exception" is the only
 * reliable success signal — never inspect a return value for it.
 *
 * ⚠️ Two failure kinds, and callers should treat them differently: a
 * HostRefusedException means the host answered NO and its `reason()` is
 * worth showing the customer (weak password, quota exceeded, duplicate);
 * any other RuntimeException means the host could not be reached, which
 * deserves "try again in a moment" and no detail.
 *
 * ⚠️ mailcow's API has an IP allowlist ("Allow from" under Configuration →
 * Access → API) — same trap as Ploi/Namecheap/Cloudflare.
 */
interface Mail
{
    // ------------------------------------------------------------------
    // Domains
    // ------------------------------------------------------------------

    /**
     * Makes the mail host accept $domain. Idempotent — a domain the host
     * already has is left alone rather than re-added, because mailcow
     * answers a duplicate add with a failure, not a no-op.
     *
     * $active false creates the domain switched OFF. Use it whenever the
     * customer has not yet proven ownership: mailcow treats a local domain
     * as authoritative and would otherwise swallow mail belonging to that
     * domain's real owner. Switch it on with setDomainActive() once the
     * DNS proof lands.
     *
     * $owner is stamped into the host's description field so ownership
     * survives outside our databases — see MailOwner.
     *
     * @param  int  $maxMailboxes  ceiling the host itself enforces
     * @param  int  $maxQuotaMb  largest size a single mailbox may be given
     * @param  int|null  $defaultQuotaMb  size a new mailbox gets when none is asked for
     * @param  int|null  $totalQuotaMb  ceiling across ALL mailboxes on the domain
     * @param  int|null  $maxAliases  ceiling on forwarding addresses
     *
     * @throws RuntimeException on any host-side failure
     */
    public function addDomain(
        string $domain,
        int $maxMailboxes = 10,
        int $maxQuotaMb = 10240,
        bool $active = true,
        ?MailOwner $owner = null,
        ?int $defaultQuotaMb = null,
        ?int $totalQuotaMb = null,
        ?int $maxAliases = null,
    ): void;

    /**
     * Every domain on the instance, as the host returns them. This is the
     * WHOLE shared instance, not one customer's — filter by owner
     * (MailOwner::fromDescription on each row's `description`) before
     * showing anything to a customer.
     *
     * @return list<array<string, mixed>>
     *
     * @throws RuntimeException on any host-side failure
     */
    public function listDomains(): array;

    /**
     * One domain as the host returns it, or null when it doesn't have it.
     *
     * @return array<string, mixed>|null
     *
     * @throws RuntimeException on any host-side failure
     */
    public function domain(string $domain): ?array;

    /**
     * Switches a domain on or off. An inactive domain keeps its mailboxes
     * and settings but accepts no mail.
     *
     * @throws RuntimeException on any host-side failure
     */
    public function setDomainActive(string $domain, bool $active): void;

    /**
     * Re-stamps who a domain belongs to, without touching anything else
     * about it. This is how a domain created before the convention gets
     * attributed, and how one is handed from one owner to another.
     *
     * The wire format stays inside the package: callers pass a MailOwner,
     * never a hand-built description string.
     *
     * @throws RuntimeException on any host-side failure
     */
    public function setDomainOwner(string $domain, MailOwner $owner): void;

    /**
     * Removes the domain and every mailbox/alias on it, so a future
     * unrelated owner of the domain can never receive mail meant for an
     * address the previous customer set up. Idempotent: a domain that's
     * already gone is success.
     *
     * @throws RuntimeException on any other host-side failure
     */
    public function deleteDomain(string $domain): void;

    // ------------------------------------------------------------------
    // DNS
    // ------------------------------------------------------------------

    /**
     * The domain's DKIM public key as a DNS TXT record. The host
     * generates the keypair the first time one is asked for; the
     * selector is normally "dkim" but not guaranteed, so it's read back
     * rather than assumed. Null when the host has no key for the domain.
     *
     * @return array{selector: string, record: string}|null
     *
     * @throws RuntimeException on any host-side failure
     */
    public function dkim(string $domain): ?array;

    /**
     * Every DNS record the domain needs for mail to flow: MX to the mail
     * host, the SPF TXT, a DMARC TXT, and the DKIM TXT once the host has
     * a key. Names are fully qualified. Entries whose value isn't
     * configured are left out rather than emitted blank.
     *
     * @return list<array{type: string, name: string, content: string, priority?: int}>
     *
     * @throws RuntimeException on any host-side failure
     */
    public function dnsRecords(string $domain): array;

    // ------------------------------------------------------------------
    // Mailboxes
    // ------------------------------------------------------------------

    /**
     * A real mailbox with storage, reachable over IMAP/SMTP/webmail.
     *
     * @param  int|null  $quotaMb  null leaves the host's per-domain default
     *
     * @throws RuntimeException on any host-side failure, including a
     *                          password the host's policy rejects
     */
    public function addMailbox(string $domain, string $localPart, string $password, string $name = '', ?int $quotaMb = null): void;

    /**
     * One mailbox as the host returns it, or null when it doesn't exist.
     *
     * @return array<string, mixed>|null
     *
     * @throws RuntimeException on any host-side failure
     */
    public function mailbox(string $email): ?array;

    /**
     * Changes an existing mailbox. Only the keys given are touched, and an
     * empty change set is a no-op rather than a call.
     *
     * `forward_to` replaces the whole forwarding list — pass an empty
     * array to stop forwarding. `forward_only` true means the mailbox
     * relays without keeping a copy.
     *
     * @param  array{password?: string, name?: string, quota_mb?: int, active?: bool, forward_to?: list<string>, forward_only?: bool}  $changes
     *
     * @throws RuntimeException on any host-side failure
     */
    public function updateMailbox(string $email, array $changes): void;

    /**
     * Switches many mailboxes on or off in ONE call — what a billing
     * webhook uses to suspend or restore a whole account. Doing it per
     * mailbox is a round trip each, inside a webhook that has to answer
     * fast. An empty list is a no-op.
     *
     * @param  list<string>  $emails
     *
     * @throws RuntimeException on any host-side failure
     */
    public function setMailboxesActive(array $emails, bool $active): void;

    /**
     * How full one mailbox is. Bytes, plus the percentage the UI shows.
     * A mailbox the host doesn't have reports zeroes rather than throwing,
     * so a dashboard listing stale rows still renders.
     *
     * @return array{used: int, total: int, percent: float}
     *
     * @throws RuntimeException on any host-side failure
     */
    public function mailboxQuota(string $email): array;

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

    // ------------------------------------------------------------------
    // Aliases
    // ------------------------------------------------------------------

    /**
     * Pure forward — mail to $address is relayed straight to $goto (an
     * external address the customer already owns), no storage involved.
     *
     * @throws RuntimeException on any host-side failure
     */
    public function addAlias(string $address, string $goto): void;

    /**
     * Deletes by address. The host keys aliases by a numeric id, so this
     * looks the id up first; deleteAliasById() skips that round trip when
     * the caller already listed them. Idempotent.
     *
     * @throws RuntimeException on any host-side failure
     */
    public function deleteAlias(string $address): void;

    /**
     * @throws RuntimeException on any host-side failure
     */
    public function deleteAliasById(int $aliasId): void;

    /**
     * @param  array{address?: string, goto?: string, active?: bool}  $changes
     *
     * @throws RuntimeException on any host-side failure
     */
    public function updateAlias(int $aliasId, array $changes): void;

    /**
     * @return list<array<string, mixed>> aliases as the host returns them
     *
     * @throws RuntimeException on any host-side failure
     */
    public function listAliases(string $domain): array;

    /**
     * Aliases across several domains. Aliases live only on the mail host
     * (no local mirror), so a plan-limit check has to ask it.
     *
     * @param  list<string>  $domains
     *
     * @throws RuntimeException on any host-side failure
     */
    public function countAliases(array $domains): int;

    // ------------------------------------------------------------------
    // Usage
    // ------------------------------------------------------------------

    /**
     * Mailbox/alias counts and storage totals across several domains —
     * one customer's dashboard figures.
     *
     * @param  list<string>  $domains
     * @return array{mailboxes: int, aliases: int, used_bytes: int, quota_bytes: int, usage_percent: float}
     *
     * @throws RuntimeException on any host-side failure
     */
    public function resourceSummary(array $domains): array;

    // ------------------------------------------------------------------
    // Sync jobs (migrating a customer's mail in from their old provider)
    // ------------------------------------------------------------------

    /**
     * @return list<array<string, mixed>> sync jobs, passwords excluded
     *
     * @throws RuntimeException on any host-side failure
     */
    public function listSyncJobs(): array;

    /**
     * @param  array<string, mixed>  $job  host-native payload (username, host1, user1, password1, …)
     *
     * @throws RuntimeException on any host-side failure
     */
    public function addSyncJob(array $job): void;

    /**
     * @throws RuntimeException on any host-side failure
     */
    public function deleteSyncJob(int $jobId): void;
}
