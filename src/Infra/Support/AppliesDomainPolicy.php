<?php

namespace Talivio\Sdk\Infra\Support;

use RuntimeException;

/**
 * The registrar-neutral half of every Registrar driver: Talivio's margin,
 * the curated TLD list and domain splitting (`talivio.infra.domains`).
 * Kept out of the drivers so switching registrar never changes what the
 * customer is quoted or which endings are sold.
 */
trait AppliesDomainPolicy
{
    /**
     * Talivio's margin on top of the reseller price, in whole percent —
     * applied to the quoted price at search time, so what the customer
     * sees, pays, and renews at all carry the same marked-up amount.
     */
    protected function withMargin(int $resellerPriceMinorUnits): int
    {
        $percent = max(0, (int) config('talivio.infra.domains.margin_percent', 0));

        return (int) round($resellerPriceMinorUnits * (100 + $percent) / 100);
    }

    /**
     * @return list<string>
     */
    protected function supportedTlds(): array
    {
        $supported = config('talivio.infra.domains.supported_tlds', []);

        if (is_string($supported)) {
            $supported = explode(',', $supported);
        }

        return array_values(array_filter(array_map(fn ($tld) => strtolower(trim((string) $tld)), (array) $supported)));
    }

    protected function supportsTld(string $extension): bool
    {
        $supported = $this->supportedTlds();

        return $supported === [] || in_array(strtolower($extension), $supported, true);
    }

    protected function guardSupportedTld(string $extension): void
    {
        if (! $this->supportsTld($extension)) {
            throw new RuntimeException("Talivio doesn't sell .{$extension} domains yet — supported endings: .".implode(', .', $this->supportedTlds()));
        }
    }

    /**
     * Splits "shop.example.com" into ["shop.example", "com"] — registrars
     * want the registrable name and extension (TLD) as separate fields,
     * and only single-label TLDs are supported (no "co.uk"-style
     * second-level TLDs) for the launch scope.
     *
     * @return array{0: string, 1: string}
     */
    protected function split(string $domain): array
    {
        $domain = strtolower(trim($domain));
        $lastDot = strrpos($domain, '.');

        if ($lastDot === false || $lastDot === 0 || $lastDot === strlen($domain) - 1) {
            throw new RuntimeException("\"{$domain}\" is not a valid domain.");
        }

        return [substr($domain, 0, $lastDot), substr($domain, $lastDot + 1)];
    }

    /**
     * The nameservers a registrar call is about to delegate to, cleaned.
     * $allowEmpty is for register(): an empty list there means "leave
     * the registrar's defaults", whereas configureNameservers() with an
     * empty list would silently park the domain.
     *
     * @param  list<string>  $nameservers
     * @return list<string>
     */
    protected function nameserverList(array $nameservers, bool $allowEmpty = false): array
    {
        $nameservers = array_values(array_unique(array_filter(array_map(fn ($ns) => strtolower(trim((string) $ns)), $nameservers))));

        if ($nameservers === [] && ! $allowEmpty) {
            throw new RuntimeException('No nameservers given to delegate the domain to.');
        }

        return $nameservers;
    }
}
