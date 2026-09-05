<?php

namespace Talivio\Sdk\Infra\Contracts;

/**
 * Reads PUBLIC DNS — what a resolver out in the world answers right now.
 *
 * ⚠️ NOT `Dns`. That contract is the control plane of the zones we run
 * (Cloudflare): it writes records and knows what we intended. This one
 * knows only what the internet currently sees, which is a different fact
 * and often the one that matters — a zone we edited a second ago and a
 * customer's own zone we have no access to both answer here, and neither
 * answers there.
 *
 * It is an interface for one reason: every product fakes it in tests.
 * Three of them had grown their own hand-rolled double over the same
 * `dns_get_record()` wrapper; `Testing\FakeDnsProbe` replaces all of them.
 *
 * Every method answers "nothing" rather than throwing when a lookup
 * fails. These calls sit behind a button a customer clicks and inside
 * scheduled sweeps, and a briefly unhappy resolver is a "not yet", not an
 * error worth propagating.
 */
interface DnsProbe
{
    /**
     * IPv4 addresses public DNS answers for $host, in the order returned.
     * Empty when the name does not resolve (yet).
     *
     * @return list<string>
     */
    public function a(string $host): array;

    /**
     * CNAME targets for $host, trailing dot stripped. Empty when there is
     * no CNAME — including when the name resolves through an A record
     * instead, which is why a caller must never read "empty" as "the
     * domain does not exist".
     *
     * @return list<string>
     */
    public function cnameTargets(string $host): array;

    /**
     * TXT values published at $host, untrimmed and exactly as returned.
     *
     * $host is the FULL name to look up, including any leading label —
     * pass `_talivio-verify.example.com`, not `example.com`. The prefix is
     * the caller's policy, not this contract's.
     *
     * @return list<string>
     */
    public function txtRecords(string $host): array;
}
