<?php

namespace Talivio\Sdk\Infra\Support;

use Talivio\Sdk\Infra\Contracts\Host;
use Talivio\Sdk\Infra\Exceptions\NotConfiguredException;

/**
 * Host contract stand-in for an environment without hosting credentials —
 * resolvable, every call throws NotConfiguredException. See
 * UnconfiguredRegistrar for why resolution must not fail.
 */
final class UnconfiguredHost implements Host
{
    /**
     * @param  list<string>  $envKeys
     */
    public function __construct(private string $service, private array $envKeys) {}

    public function serverIp(): string
    {
        throw $this->exception();
    }

    public function attachDomain(string $domain): void
    {
        throw $this->exception();
    }

    public function requestCertificate(string $domain, array $domains = [], ?string $webhookUrl = null, bool $validateViaDns = false): void
    {
        throw $this->exception();
    }

    public function certificateIssued(string $domain): bool
    {
        throw $this->exception();
    }

    public function detachDomain(string $domain): void
    {
        throw $this->exception();
    }

    public function listSites(): array
    {
        throw $this->exception();
    }

    public function createSite(string $domain, array $options = []): array
    {
        throw $this->exception();
    }

    public function deleteSite(int|string $siteId): void
    {
        throw $this->exception();
    }

    public function requestSiteCertificate(int|string $siteId, array $domains, ?string $webhookUrl = null): void
    {
        throw $this->exception();
    }

    public function siteCertificateIssued(int|string $siteId, string $domain): bool
    {
        throw $this->exception();
    }

    private function exception(): NotConfiguredException
    {
        return NotConfiguredException::forService($this->service, $this->envKeys);
    }
}
