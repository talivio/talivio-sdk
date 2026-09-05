<?php

namespace Talivio\Sdk\Infra\Contracts;

/**
 * Optional extension of Registrar for the "search a name across several
 * endings" screen: one round trip for a batch instead of one per domain.
 * Namecheap rate-limits its API (20/min per account), so a five-TLD search
 * done as five checkAvailability() calls would allow four searches a
 * minute across ALL products sharing the account.
 *
 * Kept OUT of Registrar itself so a product's test doubles of the core
 * contract keep compiling; callers `instanceof` this and fall back to
 * checkAvailability() per domain when the driver lacks it.
 */
interface BulkAvailability
{
    /**
     * Availability + quote for each domain, keyed by the lower-cased
     * domain. A domain the registrar could not check at all (an ending
     * the account can't sell, a malformed name) is simply LEFT OUT rather
     * than failing the whole batch — the search page shows what it can.
     *
     * @param  list<string>  $domains
     * @return array<string, array{available: bool, premium: bool, price: int|null, currency: string}>
     *                                                                                                 price is null when the registrar lists no price for the ending.
     */
    public function checkMany(array $domains): array;
}
