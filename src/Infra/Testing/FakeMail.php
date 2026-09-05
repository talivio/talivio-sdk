<?php

namespace Talivio\Sdk\Infra\Testing;

use RuntimeException;
use Talivio\Sdk\Infra\Contracts\Mail;
use Talivio\Sdk\Infra\Support\MailOwner;

/**
 * In-memory Mail host for product tests — bind it to the contract and
 * nothing reaches mailcow. Rows are shaped like mailcow's own so a test
 * can assert on `quota_used`, `active`, `domain_name` and friends exactly
 * as production code reads them.
 */
class FakeMail implements Mail
{
    /** @var array<string, array<string, mixed>> keyed by domain, mailcow-shaped */
    public array $domains = [];

    /** @var array<string, array<string, mixed>> keyed by address, mailcow-shaped */
    public array $mailboxes = [];

    /** @var array<int, array<string, mixed>> keyed by alias id */
    public array $aliases = [];

    /** @var array<int, array<string, mixed>> keyed by sync job id */
    public array $syncJobs = [];

    /** @var list<string> */
    public array $deletedMailboxes = [];

    /** @var list<string> */
    public array $deletedAliases = [];

    /** @var list<string> */
    public array $deletedDomains = [];

    public string $dkimSelector = 'dkim';

    /** Null = the host has no key for any domain. */
    public ?string $dkimRecord = 'v=DKIM1;k=rsa;p=FAKE';

    public ?string $mxHost = 'mail.example.test';

    public ?string $spfValue = 'v=spf1 mx -all';

    /** Set to a message to make every call throw. */
    public ?string $failWith = null;

    protected int $nextAliasId = 1;

    protected int $nextSyncJobId = 1;

    // ------------------------------------------------------------------
    // Domains
    // ------------------------------------------------------------------

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
        $this->guard();

        $domain = $this->normalize($domain);

        if (isset($this->domains[$domain])) {
            return;
        }

        $this->domains[$domain] = [
            'domain_name' => $domain,
            'description' => $owner?->toDescription() ?? 'Talivio',
            'aliases' => $maxAliases ?? 400,
            'mailboxes' => $maxMailboxes,
            'defquota' => $defaultQuotaMb ?? 1024,
            'maxquota' => $maxQuotaMb,
            'quota' => $totalQuotaMb ?? $maxQuotaMb,
            'active' => $active ? 1 : 0,
        ];
    }

    public function listDomains(): array
    {
        $this->guard();

        return array_values($this->domains);
    }

    public function domain(string $domain): ?array
    {
        $this->guard();

        return $this->domains[$this->normalize($domain)] ?? null;
    }

    public function setDomainActive(string $domain, bool $active): void
    {
        $this->guard();

        $domain = $this->normalize($domain);

        if (isset($this->domains[$domain])) {
            $this->domains[$domain]['active'] = $active ? 1 : 0;
        }
    }

    public function deleteDomain(string $domain): void
    {
        $this->guard();

        $domain = $this->normalize($domain);
        $this->deletedDomains[] = $domain;
        unset($this->domains[$domain]);

        $this->mailboxes = array_filter($this->mailboxes, fn ($m) => $m['domain'] !== $domain);
        $this->aliases = array_filter($this->aliases, fn ($a) => ! str_ends_with((string) $a['address'], '@'.$domain));
    }

    /** Test helper: the owner tag stamped on a domain, or null. */
    public function ownerOf(string $domain): ?MailOwner
    {
        return MailOwner::fromDescription($this->domains[$this->normalize($domain)]['description'] ?? null);
    }

    // ------------------------------------------------------------------
    // DNS
    // ------------------------------------------------------------------

    public function dkim(string $domain): ?array
    {
        $this->guard();

        return $this->dkimRecord === null ? null : ['selector' => $this->dkimSelector, 'record' => $this->dkimRecord];
    }

    public function dnsRecords(string $domain): array
    {
        $domain = $this->normalize($domain);
        $records = [];

        if ($this->mxHost !== null) {
            $records[] = ['type' => 'MX', 'name' => $domain, 'content' => $this->mxHost, 'priority' => 10];
        }

        if ($this->spfValue !== null) {
            $records[] = ['type' => 'TXT', 'name' => $domain, 'content' => $this->spfValue];
        }

        $records[] = ['type' => 'TXT', 'name' => "_dmarc.{$domain}", 'content' => "v=DMARC1; p=quarantine; rua=mailto:postmaster@{$domain}"];

        if ($dkim = $this->dkim($domain)) {
            $records[] = ['type' => 'TXT', 'name' => $dkim['selector'].'._domainkey.'.$domain, 'content' => $dkim['record']];
        }

        return $records;
    }

    // ------------------------------------------------------------------
    // Mailboxes
    // ------------------------------------------------------------------

    public function addMailbox(string $domain, string $localPart, string $password, string $name = '', ?int $quotaMb = null): void
    {
        $this->guard();

        $domain = $this->normalize($domain);
        $address = strtolower($localPart).'@'.$domain;

        // Passwords are deliberately NOT kept — a test double that stores
        // them invites an assertion that locks the plaintext in place.
        $this->mailboxes[$address] = [
            'username' => $address,
            'local_part' => strtolower($localPart),
            'domain' => $domain,
            'name' => $name !== '' ? $name : $localPart,
            'quota' => ($quotaMb ?? 1024) * 1024 * 1024,
            'quota_used' => 0,
            'active' => 1,
        ];
    }

    public function mailbox(string $email): ?array
    {
        $this->guard();

        return $this->mailboxes[strtolower(trim($email))] ?? null;
    }

    public function updateMailbox(string $email, array $changes): void
    {
        $this->guard();

        $email = strtolower(trim($email));

        if (! isset($this->mailboxes[$email])) {
            return;
        }

        if (isset($changes['name'])) {
            $this->mailboxes[$email]['name'] = $changes['name'];
        }

        if (isset($changes['quota_mb'])) {
            $this->mailboxes[$email]['quota'] = (int) $changes['quota_mb'] * 1024 * 1024;
        }

        if (isset($changes['active'])) {
            $this->mailboxes[$email]['active'] = $changes['active'] ? 1 : 0;
        }

        if (isset($changes['password'])) {
            $this->mailboxes[$email]['password_changed'] = true;
        }

        if (array_key_exists('forward_to', $changes)) {
            $this->mailboxes[$email]['forward_to'] = implode(',', (array) $changes['forward_to']);
        }

        if (isset($changes['forward_only'])) {
            $this->mailboxes[$email]['forward_only'] = $changes['forward_only'] ? 1 : 0;
        }
    }

    public function setMailboxesActive(array $emails, bool $active): void
    {
        $this->guard();

        foreach ($emails as $email) {
            $email = strtolower(trim((string) $email));

            if (isset($this->mailboxes[$email])) {
                $this->mailboxes[$email]['active'] = $active ? 1 : 0;
            }
        }
    }

    public function mailboxQuota(string $email): array
    {
        $this->guard();

        $mailbox = $this->mailbox($email);

        if ($mailbox === null) {
            return ['used' => 0, 'total' => 0, 'percent' => 0.0];
        }

        $used = (int) $mailbox['quota_used'];
        $total = (int) $mailbox['quota'];

        return [
            'used' => $used,
            'total' => $total,
            'percent' => $total > 0 ? round($used / $total * 100, 1) : 0.0,
        ];
    }

    public function deleteMailbox(string $email): void
    {
        $this->guard();

        $email = strtolower(trim($email));
        $this->deletedMailboxes[] = $email;
        unset($this->mailboxes[$email]);
    }

    public function listMailboxes(string $domain): array
    {
        $this->guard();

        $domain = $this->normalize($domain);

        return array_values(array_filter($this->mailboxes, fn ($m) => $m['domain'] === $domain));
    }

    // ------------------------------------------------------------------
    // Aliases
    // ------------------------------------------------------------------

    public function addAlias(string $address, string $goto): void
    {
        $this->guard();

        $id = $this->nextAliasId++;
        $this->aliases[$id] = ['id' => $id, 'address' => strtolower(trim($address)), 'goto' => $goto, 'active' => 1];
    }

    public function deleteAlias(string $address): void
    {
        $this->guard();

        $address = strtolower(trim($address));

        foreach ($this->aliases as $id => $alias) {
            if ($alias['address'] === $address) {
                $this->deletedAliases[] = $address;
                unset($this->aliases[$id]);

                return;
            }
        }
    }

    public function deleteAliasById(int $aliasId): void
    {
        $this->guard();

        if (isset($this->aliases[$aliasId])) {
            $this->deletedAliases[] = (string) $this->aliases[$aliasId]['address'];
            unset($this->aliases[$aliasId]);
        }
    }

    public function updateAlias(int $aliasId, array $changes): void
    {
        $this->guard();

        if (! isset($this->aliases[$aliasId])) {
            return;
        }

        foreach (['address', 'goto'] as $key) {
            if (isset($changes[$key])) {
                $this->aliases[$aliasId][$key] = $changes[$key];
            }
        }

        if (isset($changes['active'])) {
            $this->aliases[$aliasId]['active'] = $changes['active'] ? 1 : 0;
        }
    }

    public function listAliases(string $domain): array
    {
        $this->guard();

        $domain = $this->normalize($domain);

        return array_values(array_filter($this->aliases, fn ($a) => str_ends_with((string) $a['address'], '@'.$domain)));
    }

    public function countAliases(array $domains): int
    {
        $total = 0;

        foreach ($domains as $domain) {
            $total += count($this->listAliases((string) $domain));
        }

        return $total;
    }

    // ------------------------------------------------------------------
    // Usage
    // ------------------------------------------------------------------

    public function resourceSummary(array $domains): array
    {
        $mailboxes = 0;
        $aliases = 0;
        $used = 0;
        $quota = 0;

        foreach ($domains as $domain) {
            $rows = $this->listMailboxes((string) $domain);
            $mailboxes += count($rows);
            $aliases += count($this->listAliases((string) $domain));

            foreach ($rows as $row) {
                $used += (int) $row['quota_used'];
                $quota += (int) $row['quota'];
            }
        }

        return [
            'mailboxes' => $mailboxes,
            'aliases' => $aliases,
            'used_bytes' => $used,
            'quota_bytes' => $quota,
            'usage_percent' => $quota > 0 ? round($used / $quota * 100, 1) : 0.0,
        ];
    }

    // ------------------------------------------------------------------
    // Sync jobs
    // ------------------------------------------------------------------

    public function listSyncJobs(): array
    {
        $this->guard();

        return array_values($this->syncJobs);
    }

    public function addSyncJob(array $job): void
    {
        $this->guard();

        $id = $this->nextSyncJobId++;
        $this->syncJobs[$id] = ['id' => $id] + $job;
    }

    public function deleteSyncJob(int $jobId): void
    {
        $this->guard();

        unset($this->syncJobs[$jobId]);
    }

    // ------------------------------------------------------------------

    protected function normalize(string $domain): string
    {
        return strtolower(trim($domain));
    }

    protected function guard(): void
    {
        if ($this->failWith !== null) {
            throw new RuntimeException($this->failWith);
        }
    }
}
