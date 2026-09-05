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

    public function addDomain(
        string $domain,
        int $maxMailboxes = 10,
        int $maxQuotaMb = 10240,
        bool $active = true,
        ?MailOwner $owner = null,
        ?int $defaultQuotaMb = null,
        ?int $totalQuotaMb = null,
        ?int $maxAliases = null,
    ): void {
        throw $this->exception();
    }

    public function listDomains(): array
    {
        throw $this->exception();
    }

    public function domain(string $domain): ?array
    {
        throw $this->exception();
    }

    public function setDomainActive(string $domain, bool $active): void
    {
        throw $this->exception();
    }

    public function deleteDomain(string $domain): void
    {
        throw $this->exception();
    }

    public function dkim(string $domain): ?array
    {
        throw $this->exception();
    }

    public function dnsRecords(string $domain): array
    {
        throw $this->exception();
    }

    public function addMailbox(string $domain, string $localPart, string $password, string $name = '', ?int $quotaMb = null): void
    {
        throw $this->exception();
    }

    public function mailbox(string $email): ?array
    {
        throw $this->exception();
    }

    public function updateMailbox(string $email, array $changes): void
    {
        throw $this->exception();
    }

    public function setMailboxesActive(array $emails, bool $active): void
    {
        throw $this->exception();
    }

    public function mailboxQuota(string $email): array
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

    public function deleteAliasById(int $aliasId): void
    {
        throw $this->exception();
    }

    public function updateAlias(int $aliasId, array $changes): void
    {
        throw $this->exception();
    }

    public function listAliases(string $domain): array
    {
        throw $this->exception();
    }

    public function countAliases(array $domains): int
    {
        throw $this->exception();
    }

    public function resourceSummary(array $domains): array
    {
        throw $this->exception();
    }

    public function listSyncJobs(): array
    {
        throw $this->exception();
    }

    public function addSyncJob(array $job): void
    {
        throw $this->exception();
    }

    public function deleteSyncJob(int $jobId): void
    {
        throw $this->exception();
    }

    private function exception(): NotConfiguredException
    {
        return NotConfiguredException::forService($this->service, $this->envKeys);
    }
}
