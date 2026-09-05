<?php

namespace Talivio\Sdk\Infra\Support;

use Talivio\Sdk\Infra\Contracts\DnsProbe;

/**
 * Proves that the customer CLAIMING a domain is the one who controls it.
 *
 * ⚠️ WHY TWO CHECKS AND NOT ONE. A CNAME pointing at the platform only
 * shows the domain routes here. The CNAME target is public — it is printed
 * in the product's own UI — so any other tenant could add the same domain
 * string and have that check pass the moment the domain's real owner
 * points it at us for their own reasons, and the WRONG tenant's row would
 * be marked verified and start serving that domain. The per-claim token is
 * shown to one tenant only, and nobody but whoever controls the domain's
 * DNS can publish it. Shops and Contentio each arrived at this pair
 * independently and each wrote it out again; this is that agreement, once.
 *
 * ⚠️ WHAT THIS IS NOT. Mailio proves ownership for MAIL with its own TXT
 * record at the apex and no CNAME check at all, because a mail domain
 * routes nothing to us and the question there is only "is this yours".
 * That proof stays in Mailio (`MailDomainVerifier`) — it is a different
 * question with a different answer, not a fourth copy of this one.
 *
 * Nameserver delegation is a third, separate case and needs none of this:
 * only the registrant can repoint NS at the registrar, so the zone going
 * active IS the proof. Products check that through `Dns::zoneIsActive()`.
 */
class DomainOwnership
{
    /**
     * The label the ownership token is published under. A subdomain, so a
     * customer can publish it without touching the records that carry
     * their live traffic or mail.
     */
    public const TOKEN_HOST_PREFIX = '_talivio-verify.';

    public function __construct(protected DnsProbe $dns) {}

    /**
     * Both halves. A product that wants to tell the customer WHICH half is
     * missing should call the two below instead — the wording belongs to
     * the product, not here.
     *
     * @param  list<string>  $acceptedTargets  hostnames a CNAME may point at:
     *                                         the platform's current domain plus any still-live legacy one, so a
     *                                         customer who has not repointed DNS since the last platform move is
     *                                         not locked out of verifying.
     */
    public function verify(string $domain, string $token, array $acceptedTargets): bool
    {
        return $this->cnamePointsAtAny($domain, $acceptedTargets)
            && $this->tokenIsPublished($domain, $token);
    }

    /**
     * @param  list<string>  $acceptedTargets
     */
    public function cnamePointsAtAny(string $domain, array $acceptedTargets): bool
    {
        $accepted = array_filter(array_map(
            fn (string $host) => strtolower(rtrim(trim($host), '.')),
            $acceptedTargets,
        ));

        if ($accepted === []) {
            return false;
        }

        foreach ($this->dns->cnameTargets($domain) as $target) {
            if (in_array(strtolower(rtrim(trim($target), '.')), $accepted, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * An empty token is never a pass. Without this a row whose token was
     * never generated would verify against a domain publishing no TXT at
     * all, which is the whole check inverted.
     */
    public function tokenIsPublished(string $domain, string $token): bool
    {
        $token = trim($token);

        if ($token === '') {
            return false;
        }

        foreach ($this->dns->txtRecords($this->tokenHost($domain)) as $value) {
            if (trim($value) === $token) {
                return true;
            }
        }

        return false;
    }

    /** Where the customer must publish the token, ready to show them. */
    public function tokenHost(string $domain): string
    {
        return self::TOKEN_HOST_PREFIX.strtolower(trim($domain));
    }
}
