<?php

namespace Talivio\Sdk\Infra\Testing;

use RuntimeException;
use Talivio\Sdk\Infra\Contracts\BulkAvailability;
use Talivio\Sdk\Infra\Contracts\Registrar;
use Talivio\Sdk\Infra\Exceptions\AuthCodeUnavailableException;

/**
 * In-memory Registrar for product tests — bind it to the contract and
 * nothing reaches Namecheap. Records what was asked of it; tests flip the
 * public knobs to simulate a taken name, a transfer that's still pending,
 * or a registrar outage.
 */
class FakeRegistrar implements BulkAvailability, Registrar
{
    /** @var list<string> domains reported as taken */
    public array $taken = [];

    /** @var list<string> domains reported as premium (never sold) */
    public array $premium = [];

    public int $price = 1200;

    public string $currency = 'USD';

    /** @var array<string, array{registrant: array<string, mixed>, nameservers: list<string>}> keyed by domain */
    public array $registered = [];

    /** @var array<string, int> years renewed per domain */
    public array $renewed = [];

    /** @var array<string, array{auth_code: string, registrant: array<string, mixed>}> keyed by domain */
    public array $transfers = [];

    /** @var array<string, string> transfer id → status */
    public array $transferStatuses = [];

    /** @var array<string, list<string>> keyed by domain */
    public array $nameservers = [];

    /** @var array<string, bool> keyed by domain */
    public array $locks = [];

    public ?string $authCode = 'EPP-FAKE';

    /** Set to a message to make every call throw. */
    public ?string $failWith = null;

    protected int $nextId = 1000;

    public function checkAvailability(string $domain): array
    {
        $this->guard();

        $domain = strtolower(trim($domain));
        $premium = in_array($domain, $this->premium, true);

        return [
            'available' => ! in_array($domain, $this->taken, true) && ! $premium,
            'premium' => $premium,
            'price' => $this->price,
            'currency' => $this->currency,
        ];
    }

    public function checkMany(array $domains): array
    {
        $results = [];

        foreach ($domains as $domain) {
            $results[strtolower(trim((string) $domain))] = $this->checkAvailability((string) $domain);
        }

        return $results;
    }

    public function register(string $domain, array $registrant, array $nameservers): string
    {
        $this->guard();

        $domain = strtolower(trim($domain));
        $this->registered[$domain] = ['registrant' => $registrant, 'nameservers' => array_values($nameservers)];

        if ($nameservers !== []) {
            $this->nameservers[$domain] = array_values($nameservers);
        }

        return (string) $this->nextId++;
    }

    public function renew(string $domain, string $registrarDomainId, int $years = 1): void
    {
        $this->guard();

        $domain = strtolower(trim($domain));
        $this->renewed[$domain] = ($this->renewed[$domain] ?? 0) + $years;
    }

    public function transferIn(string $domain, string $authCode, array $registrant): string
    {
        $this->guard();

        $id = (string) $this->nextId++;
        $this->transfers[strtolower(trim($domain))] = ['auth_code' => $authCode, 'registrant' => $registrant];
        $this->transferStatuses[$id] ??= 'pending';

        return $id;
    }

    public function transferStatus(string $registrarDomainId): string
    {
        $this->guard();

        return $this->transferStatuses[$registrarDomainId] ?? 'pending';
    }

    public function configureNameservers(string $domain, string $registrarDomainId, array $nameservers): void
    {
        $this->guard();

        $this->nameservers[strtolower(trim($domain))] = array_values($nameservers);
    }

    public function getAuthCode(string $domain, string $registrarDomainId): string
    {
        $this->guard();

        if ($this->authCode === null) {
            throw AuthCodeUnavailableException::forRegistrar('fake');
        }

        return $this->authCode;
    }

    public function setTransferLock(string $domain, string $registrarDomainId, bool $locked): void
    {
        $this->guard();

        $this->locks[strtolower(trim($domain))] = $locked;
    }

    protected function guard(): void
    {
        if ($this->failWith !== null) {
            throw new RuntimeException($this->failWith);
        }
    }
}
