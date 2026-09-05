<?php

namespace Talivio\Sdk\Infra\Testing;

use RuntimeException;
use Talivio\Sdk\Infra\Contracts\Mail;

/**
 * In-memory Mail host for product tests — bind it to the contract and
 * nothing reaches mailcow. Records what was asked of it.
 */
class FakeMail implements Mail
{
    /** @var array<string, array{max_mailboxes: int, max_quota_mb: int}> keyed by domain */
    public array $domains = [];

    /** @var array<string, array{domain: string, local_part: string, name: string}> keyed by address; passwords are deliberately NOT kept */
    public array $mailboxes = [];

    /** @var array<string, string> address → goto */
    public array $aliases = [];

    /** @var list<string> */
    public array $deletedMailboxes = [];

    /** @var list<string> */
    public array $deletedAliases = [];

    public string $dkimSelector = 'dkim';

    /** Null = the host has no key for any domain. */
    public ?string $dkimRecord = 'v=DKIM1;k=rsa;p=FAKE';

    public ?string $mxHost = 'mail.example.test';

    public ?string $spfValue = 'v=spf1 mx -all';

    /** Set to a message to make every call throw. */
    public ?string $failWith = null;

    public function addDomain(string $domain, int $maxMailboxes = 10, int $maxQuotaMb = 10240): void
    {
        $this->guard();

        $this->domains[strtolower(trim($domain))] = ['max_mailboxes' => $maxMailboxes, 'max_quota_mb' => $maxQuotaMb];
    }

    public function dkim(string $domain): ?array
    {
        $this->guard();

        return $this->dkimRecord === null ? null : ['selector' => $this->dkimSelector, 'record' => $this->dkimRecord];
    }

    public function dnsRecords(string $domain): array
    {
        $domain = strtolower(trim($domain));
        $records = [];

        if ($this->mxHost !== null) {
            $records[] = ['type' => 'MX', 'name' => $domain, 'content' => $this->mxHost, 'priority' => 10];
        }

        if ($this->spfValue !== null) {
            $records[] = ['type' => 'TXT', 'name' => $domain, 'content' => $this->spfValue];
        }

        if ($dkim = $this->dkim($domain)) {
            $records[] = ['type' => 'TXT', 'name' => $dkim['selector'].'._domainkey.'.$domain, 'content' => $dkim['record']];
        }

        return $records;
    }

    public function addMailbox(string $domain, string $localPart, string $password, string $name = ''): void
    {
        $this->guard();

        $domain = strtolower(trim($domain));
        $this->mailboxes[strtolower($localPart).'@'.$domain] = ['domain' => $domain, 'local_part' => $localPart, 'name' => $name !== '' ? $name : $localPart];
    }

    public function deleteMailbox(string $email): void
    {
        $this->guard();

        $this->deletedMailboxes[] = $email;
        unset($this->mailboxes[strtolower($email)]);
    }

    public function listMailboxes(string $domain): array
    {
        $this->guard();

        $domain = strtolower(trim($domain));
        $list = [];

        foreach ($this->mailboxes as $address => $mailbox) {
            if ($mailbox['domain'] === $domain) {
                $list[] = ['username' => $address, 'local_part' => $mailbox['local_part'], 'domain' => $domain, 'name' => $mailbox['name'], 'active' => 1];
            }
        }

        return $list;
    }

    public function addAlias(string $address, string $goto): void
    {
        $this->guard();

        $this->aliases[strtolower($address)] = $goto;
    }

    public function deleteAlias(string $address): void
    {
        $this->guard();

        $this->deletedAliases[] = $address;
        unset($this->aliases[strtolower($address)]);
    }

    public function listAliases(string $domain): array
    {
        $this->guard();

        $domain = strtolower(trim($domain));
        $list = [];

        foreach ($this->aliases as $address => $goto) {
            if (str_ends_with($address, '@'.$domain)) {
                $list[] = ['address' => $address, 'goto' => $goto, 'domain' => $domain, 'active' => 1];
            }
        }

        return $list;
    }

    protected function guard(): void
    {
        if ($this->failWith !== null) {
            throw new RuntimeException($this->failWith);
        }
    }
}
