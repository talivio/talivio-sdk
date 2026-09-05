<?php

namespace Talivio\Sdk\Infra\Contracts;

use RuntimeException;
use Talivio\Sdk\Infra\Exceptions\HostRefusedException;

/**
 * Mail provisioning for a PRODUCT's customers, through Mailio.
 *
 * ⚠️ THIS IS THE ONE A PRODUCT SHOULD USE. `Mail` is the raw mail host
 * (mailcow) and reaches the whole shared instance — including the
 * hand-run customer domains that belong to nobody's product. `ProductMail`
 * goes through Mailio, which is the owner-of-record for every mail package
 * Talivio sells (2026-09-05 decision), and every call is scoped to the
 * calling product's own customers. A product that talks to `Mail`
 * directly is one bug away from touching another customer's mail.
 *
 * Identity comes from the credential, never from an argument: there is no
 * "which product am I" parameter, because the key already answers that.
 * `$customerRef` is the PRODUCT's own id for its customer (e.g. "site-42").
 *
 * ⚠️ Failure split, same as everywhere in Infra: a HostRefusedException
 * means Mailio answered NO and its `reason()` is worth showing the
 * customer (domain taken, not verified yet, weak password); any other
 * RuntimeException means Mailio could not be reached, which deserves
 * "try again in a moment" and no detail.
 */
interface ProductMail
{
    /**
     * Domains this product has provisioned, optionally just one
     * customer's. Never includes another product's or a hand-run domain.
     *
     * @return list<array{domain: string, customer_ref: string, verified: bool, verification_token: ?string, max_mailboxes: int, default_quota_mb: int}>
     *
     * @throws RuntimeException when Mailio is unreachable
     */
    public function domains(?string $customerRef = null): array;

    /**
     * Registers a domain for one of this product's customers.
     *
     * It is created switched OFF at the mail host and stays that way until
     * verifyDomain() sees the ownership TXT record — an unverified domain
     * on a shared instance would swallow mail belonging to its real owner.
     *
     * The ceilings are the ones THIS product sold; Mailio does not apply
     * its own plan limits to another product's customer.
     *
     * @param  array{label?: string, mailboxes?: int, quota_mb?: int, total_quota_mb?: int, aliases?: int}  $limits
     * @return array{domain: string, customer_ref: string, verified: bool, verification_token: ?string, max_mailboxes: int, default_quota_mb: int}
     *
     * @throws HostRefusedException when the domain is already registered — by
     *                              anyone. Which product or customer holds it
     *                              is deliberately not disclosed.
     * @throws RuntimeException when Mailio is unreachable
     */
    public function createDomain(string $customerRef, string $domain, array $limits = []): array;

    /**
     * Every DNS record the customer must publish: the ownership TXT while
     * the domain is unverified, then MX/SPF/DMARC/DKIM. `value` is what to
     * publish and `description` explains why, ready to show.
     *
     * When the product runs the domain's zone itself (Dns::upsertRecord)
     * it can publish these without the customer touching anything.
     *
     * @return list<array{type: string, name: string, value: string, priority?: int, description: string, is_verification: bool}>
     *
     * @throws HostRefusedException when this product does not own the domain
     * @throws RuntimeException when Mailio is unreachable
     */
    public function dnsRecords(string $domain): array;

    /**
     * Re-checks the ownership TXT and, if it is live, switches the domain
     * on at the mail host. Safe to call repeatedly; the answer says where
     * things stand rather than throwing while still unverified.
     *
     * @return array{domain: string, customer_ref: string, verified: bool, verification_token: ?string, max_mailboxes: int, default_quota_mb: int}
     *
     * @throws HostRefusedException when this product does not own the domain
     * @throws RuntimeException when Mailio is unreachable
     */
    public function verifyDomain(string $domain): array;

    /**
     * Removes the domain and every mailbox and alias on it.
     *
     * @throws HostRefusedException when this product does not own the domain
     * @throws RuntimeException when Mailio is unreachable
     */
    public function deleteDomain(string $domain): void;

    /**
     * A real mailbox with storage. The domain must be verified first.
     *
     * @return array{address: string, name: string, quota_mb: int}
     *
     * @throws HostRefusedException when the domain is not this product's, not
     *                              verified, or the host rejects the password
     * @throws RuntimeException when Mailio is unreachable
     */
    public function createMailbox(string $domain, string $localPart, string $password, string $name = '', ?int $quotaMb = null): array;

    /**
     * @throws HostRefusedException when the mailbox is not this product's
     * @throws RuntimeException when Mailio is unreachable
     */
    public function deleteMailbox(string $address): void;

    /**
     * Forward-only address: mail to $address is relayed to $goto, with no
     * storage of its own.
     *
     * @throws HostRefusedException when the domain is not this product's or not verified
     * @throws RuntimeException when Mailio is unreachable
     */
    public function createAlias(string $address, string $goto): void;

    /**
     * @throws HostRefusedException when the domain is not this product's
     * @throws RuntimeException when Mailio is unreachable
     */
    public function deleteAlias(string $address): void;

    /**
     * Mailbox/alias counts and storage totals across this product's
     * domains, or one customer's.
     *
     * @return array{mailboxes: int, aliases: int, used_bytes: int, quota_bytes: int, usage_percent: float}
     *
     * @throws RuntimeException when Mailio is unreachable
     */
    public function usage(?string $customerRef = null): array;
}
