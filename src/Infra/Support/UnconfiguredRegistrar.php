<?php

namespace Talivio\Sdk\Infra\Support;

use Talivio\Sdk\Infra\Contracts\BulkAvailability;
use Talivio\Sdk\Infra\Contracts\Registrar;
use Talivio\Sdk\Infra\Exceptions\NotConfiguredException;

/**
 * What the Registrar contract resolves to when the configured driver has
 * no credentials in the environment. Every call throws
 * NotConfiguredException naming the missing variables — but RESOLVING it
 * is fine, so a controller that constructor-injects the contract still
 * builds (its unrelated actions keep working) and only the action that
 * actually reaches the registrar fails, with a message that says why.
 *
 * Products that want to show "not configured" instead of failing call
 * the concrete client's `fromConfig()` (null when unconfigured).
 */
final class UnconfiguredRegistrar implements BulkAvailability, Registrar
{
    /**
     * @param  list<string>  $envKeys
     */
    public function __construct(private string $service, private array $envKeys) {}

    public function checkAvailability(string $domain): array
    {
        throw $this->exception();
    }

    public function checkMany(array $domains): array
    {
        throw $this->exception();
    }

    public function register(string $domain, array $registrant, array $nameservers): string
    {
        throw $this->exception();
    }

    public function renew(string $domain, string $registrarDomainId, int $years = 1): void
    {
        throw $this->exception();
    }

    public function transferIn(string $domain, string $authCode, array $registrant): string
    {
        throw $this->exception();
    }

    public function transferStatus(string $registrarDomainId): string
    {
        throw $this->exception();
    }

    public function configureNameservers(string $domain, string $registrarDomainId, array $nameservers): void
    {
        throw $this->exception();
    }

    public function getAuthCode(string $domain, string $registrarDomainId): string
    {
        throw $this->exception();
    }

    public function setTransferLock(string $domain, string $registrarDomainId, bool $locked): void
    {
        throw $this->exception();
    }

    private function exception(): NotConfiguredException
    {
        return NotConfiguredException::forService($this->service, $this->envKeys);
    }
}
