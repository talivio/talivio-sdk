<?php

namespace Talivio\Sdk\Tests\Infra;

use PHPUnit\Framework\TestCase;
use Talivio\Sdk\Infra\Support\SystemDnsProbe;
use Talivio\Sdk\Infra\Testing\FakeDnsProbe;

/**
 * The real probe cannot be tested against live DNS without making the
 * suite depend on the network, so what is pinned here is the shaping it
 * does around `dns_get_record()` — the part products relied on and each
 * re-implemented slightly differently.
 */
class SystemDnsProbeTest extends TestCase
{
    public function test_a_failed_lookup_is_an_empty_answer_not_an_error(): void
    {
        $probe = new class extends SystemDnsProbe
        {
            protected function lookup(string $host, int $type): array
            {
                // What dns_get_record() actually returns for NXDOMAIN,
                // SERVFAIL and timeouts alike.
                return [];
            }
        };

        $this->assertSame([], $probe->a('nothing-here.invalid'));
        $this->assertSame([], $probe->cnameTargets('nothing-here.invalid'));
        $this->assertSame([], $probe->txtRecords('nothing-here.invalid'));
    }

    public function test_cname_targets_come_back_without_the_trailing_dot(): void
    {
        $probe = new class extends SystemDnsProbe
        {
            protected function lookup(string $host, int $type): array
            {
                return [['target' => 'shops.talivio.com.']];
            }
        };

        $this->assertSame(['shops.talivio.com'], $probe->cnameTargets('acme.com'));
    }

    /**
     * A record with the key missing would otherwise become an empty string
     * in the list and could compare equal to an empty expectation.
     */
    public function test_records_missing_the_value_are_dropped(): void
    {
        $probe = new class extends SystemDnsProbe
        {
            protected function lookup(string $host, int $type): array
            {
                return [['ip' => '31.220.77.127'], ['type' => 'A'], ['ip' => '']];
            }
        };

        $this->assertSame(['31.220.77.127'], $probe->a('acme.com'));
    }

    /** The fake has to answer the way the real one does, or it is not a fake. */
    public function test_the_fake_matches_hosts_the_way_a_resolver_answers_them(): void
    {
        $fake = (new FakeDnsProbe)->withCname('ACME.com.', ['shops.talivio.com.']);

        $this->assertSame(['shops.talivio.com'], $fake->cnameTargets('acme.com'));
        $this->assertSame([], $fake->cnameTargets('other.com'));
    }
}
