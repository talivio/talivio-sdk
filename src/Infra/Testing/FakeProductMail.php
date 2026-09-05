<?php

namespace Talivio\Sdk\Infra\Testing;

use RuntimeException;
use Talivio\Sdk\Infra\Contracts\ProductMail;
use Talivio\Sdk\Infra\Exceptions\HostRefusedException;

/**
 * In-memory ProductMail for product tests — bind it and nothing reaches
 * Mailio. Enforces the same rules the real gateway does (a domain must be
 * verified before it can hold addresses, a domain can only be registered
 * once) so a test cannot pass against a fake that is more permissive than
 * production.
 */
class FakeProductMail implements ProductMail
{
    /** @var array<string, array<string, mixed>> keyed by domain */
    public array $domains = [];

    /** @var array<string, array{address: string, name: string, quota_mb: int, domain: string}> keyed by address */
    public array $mailboxes = [];

    /** @var array<string, string> alias address => goto */
    public array $aliases = [];

    /** @var list<string> */
    public array $deletedDomains = [];

    /** @var list<string> */
    public array $deletedMailboxes = [];

    /** @var list<string> */
    public array $deletedAliases = [];

    /** Domains registered by SOMEONE ELSE — createDomain() refuses these. */
    public array $takenElsewhere = [];

    /** Set to a message to make every call throw as an outage. */
    public ?string $failWith = null;

    public string $mxHost = 'mail.example.test';

    public function domains(?string $customerRef = null): array
    {
        $this->guard();

        return array_values(array_filter(
            $this->domains,
            fn ($d) => $customerRef === null || $d['customer_ref'] === $customerRef,
        ));
    }

    public function createDomain(string $customerRef, string $domain, array $limits = []): array
    {
        $this->guard();

        $domain = $this->normalize($domain);

        if (isset($this->domains[$domain]) || in_array($domain, $this->takenElsewhere, true)) {
            throw HostRefusedException::withReason('Mailio', 'This domain is already registered in the system.');
        }

        return $this->domains[$domain] = [
            'domain' => $domain,
            'customer_ref' => $customerRef,
            'verified' => false,
            'verification_token' => 'mailio-verify=fake-'.substr(md5($domain), 0, 12),
            'max_mailboxes' => (int) ($limits['mailboxes'] ?? 10),
            'default_quota_mb' => (int) ($limits['quota_mb'] ?? 1024),
        ];
    }

    public function dnsRecords(string $domain): array
    {
        $record = $this->owned($domain);
        $records = [];

        if (! $record['verified']) {
            $records[] = [
                'type' => 'TXT', 'name' => $record['domain'], 'value' => $record['verification_token'],
                'description' => 'Domain Ownership Verification', 'is_verification' => true,
            ];
        }

        $records[] = ['type' => 'MX', 'name' => $record['domain'], 'value' => $this->mxHost, 'priority' => 10, 'description' => 'Mail exchange record.', 'is_verification' => false];
        $records[] = ['type' => 'TXT', 'name' => $record['domain'], 'value' => 'v=spf1 mx -all', 'description' => 'SPF record.', 'is_verification' => false];
        $records[] = ['type' => 'TXT', 'name' => '_dmarc.'.$record['domain'], 'value' => 'v=DMARC1; p=quarantine;', 'description' => 'DMARC record.', 'is_verification' => false];
        $records[] = ['type' => 'TXT', 'name' => 'dkim._domainkey.'.$record['domain'], 'value' => 'v=DKIM1;k=rsa;p=FAKE', 'description' => 'DKIM record.', 'is_verification' => false];

        return $records;
    }

    /** Test helper: pretend the customer published the ownership record. */
    public function markVerified(string $domain): void
    {
        $domain = $this->normalize($domain);

        if (isset($this->domains[$domain])) {
            $this->domains[$domain]['verified'] = true;
        }
    }

    public function verifyDomain(string $domain): array
    {
        $record = $this->owned($domain);

        return $this->domains[$record['domain']];
    }

    public function deleteDomain(string $domain): void
    {
        $record = $this->owned($domain);
        $name = $record['domain'];

        $this->deletedDomains[] = $name;
        unset($this->domains[$name]);

        $this->mailboxes = array_filter($this->mailboxes, fn ($m) => $m['domain'] !== $name);
        $this->aliases = array_filter($this->aliases, fn ($goto, $address) => ! str_ends_with($address, '@'.$name), ARRAY_FILTER_USE_BOTH);
    }

    public function createMailbox(string $domain, string $localPart, string $password, string $name = '', ?int $quotaMb = null): array
    {
        $record = $this->usable($domain);
        $address = strtolower(trim($localPart)).'@'.$record['domain'];

        if (isset($this->mailboxes[$address])) {
            throw HostRefusedException::withReason('Mailio', 'This address already exists.');
        }

        // Passwords are deliberately not kept.
        return $this->mailboxes[$address] = [
            'address' => $address,
            'name' => $name !== '' ? $name : $localPart,
            'quota_mb' => $quotaMb ?? $record['default_quota_mb'],
            'domain' => $record['domain'],
        ];
    }

    public function deleteMailbox(string $address): void
    {
        $this->guard();

        $address = strtolower(trim($address));

        if (! isset($this->mailboxes[$address])) {
            throw HostRefusedException::withReason('Mailio', 'Mailbox not found or unauthorized.');
        }

        $this->deletedMailboxes[] = $address;
        unset($this->mailboxes[$address]);
    }

    public function createAlias(string $address, string $goto): void
    {
        $address = strtolower(trim($address));
        $this->usable((string) substr(strrchr($address, '@') ?: '', 1));

        $this->aliases[$address] = $goto;
    }

    public function deleteAlias(string $address): void
    {
        $address = strtolower(trim($address));
        $this->owned((string) substr(strrchr($address, '@') ?: '', 1));

        $this->deletedAliases[] = $address;
        unset($this->aliases[$address]);
    }

    public function usage(?string $customerRef = null): array
    {
        $this->guard();

        $domains = array_column($this->domains($customerRef), 'domain');
        $mailboxes = array_filter($this->mailboxes, fn ($m) => in_array($m['domain'], $domains, true));
        $aliases = array_filter($this->aliases, fn ($goto, $a) => in_array((string) substr(strrchr($a, '@') ?: '', 1), $domains, true), ARRAY_FILTER_USE_BOTH);

        $quota = array_sum(array_map(fn ($m) => $m['quota_mb'] * 1024 * 1024, $mailboxes));

        return [
            'mailboxes' => count($mailboxes),
            'aliases' => count($aliases),
            'used_bytes' => 0,
            'quota_bytes' => $quota,
            'usage_percent' => 0.0,
        ];
    }

    // ------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    protected function owned(string $domain): array
    {
        $this->guard();

        $domain = $this->normalize($domain);

        if (! isset($this->domains[$domain])) {
            // Exactly what the gateway says for someone else's domain, so a
            // test cannot tell "not mine" from "does not exist" either.
            throw HostRefusedException::withReason('Mailio', 'Domain not found or unauthorized.');
        }

        return $this->domains[$domain];
    }

    /**
     * @return array<string, mixed>
     */
    protected function usable(string $domain): array
    {
        $record = $this->owned($domain);

        if (! $record['verified']) {
            throw HostRefusedException::withReason('Mailio', 'This domain is not verified yet.');
        }

        return $record;
    }

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
