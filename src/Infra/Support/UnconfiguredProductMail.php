<?php

namespace Talivio\Sdk\Infra\Support;

use Talivio\Sdk\Infra\Contracts\ProductMail;
use Talivio\Sdk\Infra\Exceptions\NotConfiguredException;

/**
 * ProductMail stand-in for a product that has no Mailio key yet —
 * resolvable, every call throws NotConfiguredException naming the env
 * variables. See UnconfiguredRegistrar for why resolution must not fail.
 */
final class UnconfiguredProductMail implements ProductMail
{
    /**
     * @param  list<string>  $envKeys
     */
    public function __construct(private string $service, private array $envKeys) {}

    public function domains(?string $customerRef = null): array
    {
        throw $this->exception();
    }

    public function createDomain(string $customerRef, string $domain, array $limits = []): array
    {
        throw $this->exception();
    }

    public function dnsRecords(string $domain): array
    {
        throw $this->exception();
    }

    public function verifyDomain(string $domain): array
    {
        throw $this->exception();
    }

    public function deleteDomain(string $domain): void
    {
        throw $this->exception();
    }

    public function createMailbox(string $domain, string $localPart, string $password, string $name = '', ?int $quotaMb = null): array
    {
        throw $this->exception();
    }

    public function deleteMailbox(string $address): void
    {
        throw $this->exception();
    }

    public function createAlias(string $address, string $goto): void
    {
        throw $this->exception();
    }

    public function deleteAlias(string $address): void
    {
        throw $this->exception();
    }

    public function usage(?string $customerRef = null): array
    {
        throw $this->exception();
    }

    private function exception(): NotConfiguredException
    {
        return NotConfiguredException::forService($this->service, $this->envKeys);
    }
}
