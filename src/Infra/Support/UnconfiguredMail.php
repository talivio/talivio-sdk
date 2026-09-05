<?php

namespace Talivio\Sdk\Infra\Support;

use Talivio\Sdk\Infra\Contracts\Mail;
use Talivio\Sdk\Infra\Exceptions\NotConfiguredException;

/**
 * Mail contract stand-in for an environment without mail-host credentials
 * — resolvable, every call throws NotConfiguredException. See
 * UnconfiguredRegistrar for why resolution must not fail.
 */
final class UnconfiguredMail implements Mail
{
    /**
     * @param  list<string>  $envKeys
     */
    public function __construct(private string $service, private array $envKeys) {}

    public function addDomain(string $domain, int $maxMailboxes = 10, int $maxQuotaMb = 10240): void
    {
        throw $this->exception();
    }

    public function dkim(string $domain): ?array
    {
        throw $this->exception();
    }

    public function deleteDomain(string $domain): void
    {
        throw $this->exception();
    }

    public function dnsRecords(string $domain): array
    {
        throw $this->exception();
    }

    public function addMailbox(string $domain, string $localPart, string $password, string $name = ''): void
    {
        throw $this->exception();
    }

    public function deleteMailbox(string $email): void
    {
        throw $this->exception();
    }

    public function listMailboxes(string $domain): array
    {
        throw $this->exception();
    }

    public function addAlias(string $address, string $goto): void
    {
        throw $this->exception();
    }

    public function deleteAlias(string $address): void
    {
        throw $this->exception();
    }

    public function listAliases(string $domain): array
    {
        throw $this->exception();
    }

    private function exception(): NotConfiguredException
    {
        return NotConfiguredException::forService($this->service, $this->envKeys);
    }
}
