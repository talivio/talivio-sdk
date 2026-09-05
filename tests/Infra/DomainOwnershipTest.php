<?php

namespace Talivio\Sdk\Tests\Infra;

use PHPUnit\Framework\TestCase;
use Talivio\Sdk\Infra\Support\DomainOwnership;
use Talivio\Sdk\Infra\Testing\FakeDnsProbe;

class DomainOwnershipTest extends TestCase
{
    private function ownership(FakeDnsProbe $dns): DomainOwnership
    {
        return new DomainOwnership($dns);
    }

    public function test_a_domain_with_both_the_cname_and_the_token_verifies(): void
    {
        $dns = (new FakeDnsProbe)
            ->withCname('acme.com', ['shops.talivio.com'])
            ->publishToken('acme.com', 'tok-123');

        $this->assertTrue($this->ownership($dns)->verify('acme.com', 'tok-123', ['shops.talivio.com']));
    }

    /**
     * The whole reason the token exists. The CNAME target is printed in the
     * product's own UI, so this state — routes here, but the claimant never
     * proved anything — is reachable by any tenant who types the domain in.
     */
    public function test_a_cname_alone_is_not_ownership(): void
    {
        $dns = (new FakeDnsProbe)->withCname('acme.com', ['shops.talivio.com']);

        $ownership = $this->ownership($dns);

        $this->assertTrue($ownership->cnamePointsAtAny('acme.com', ['shops.talivio.com']));
        $this->assertFalse($ownership->verify('acme.com', 'tok-123', ['shops.talivio.com']));
    }

    public function test_a_token_alone_is_not_enough_either(): void
    {
        $dns = (new FakeDnsProbe)->publishToken('acme.com', 'tok-123');

        $this->assertFalse($this->ownership($dns)->verify('acme.com', 'tok-123', ['shops.talivio.com']));
    }

    public function test_another_tenants_token_does_not_verify_this_claim(): void
    {
        $dns = (new FakeDnsProbe)
            ->withCname('acme.com', ['shops.talivio.com'])
            ->publishToken('acme.com', 'someone-elses-token');

        $this->assertFalse($this->ownership($dns)->verify('acme.com', 'tok-123', ['shops.talivio.com']));
    }

    /**
     * A row whose token was never generated must not sail through against a
     * domain that publishes no TXT at all — that would invert the check.
     */
    public function test_an_empty_token_never_passes(): void
    {
        $dns = (new FakeDnsProbe)->withCname('acme.com', ['shops.talivio.com']);

        $this->assertFalse($this->ownership($dns)->tokenIsPublished('acme.com', ''));
        $this->assertFalse($this->ownership($dns)->verify('acme.com', '   ', ['shops.talivio.com']));
    }

    /**
     * A customer who has not repointed DNS since the platform's last domain
     * move should not be locked out of verifying.
     */
    public function test_a_legacy_platform_domain_is_still_accepted(): void
    {
        $dns = (new FakeDnsProbe)
            ->withCname('acme.com', ['old.talivio.shop'])
            ->publishToken('acme.com', 'tok-123');

        $this->assertTrue($this->ownership($dns)->verify(
            'acme.com', 'tok-123', ['shops.talivio.com', 'old.talivio.shop'],
        ));
    }

    public function test_an_empty_accepted_list_accepts_nothing(): void
    {
        $dns = (new FakeDnsProbe)->withCname('acme.com', ['shops.talivio.com']);

        $this->assertFalse($this->ownership($dns)->cnamePointsAtAny('acme.com', []));
        $this->assertFalse($this->ownership($dns)->cnamePointsAtAny('acme.com', ['']));
    }

    /** Real answers come back with a trailing dot and arbitrary case. */
    public function test_a_trailing_dot_and_case_do_not_change_the_answer(): void
    {
        $dns = (new FakeDnsProbe)->withCname('acme.com', ['Shops.Talivio.Com.']);

        $this->assertTrue($this->ownership($dns)->cnamePointsAtAny('acme.com', ['shops.talivio.com.']));
    }

    public function test_a_domain_pointing_somewhere_else_entirely_fails(): void
    {
        $dns = (new FakeDnsProbe)
            ->withCname('acme.com', ['ghs.googlehosted.com'])
            ->publishToken('acme.com', 'tok-123');

        $this->assertFalse($this->ownership($dns)->verify('acme.com', 'tok-123', ['shops.talivio.com']));
    }

    /** Nothing published at all is a "not yet", never an error. */
    public function test_an_unresolvable_domain_simply_does_not_verify(): void
    {
        $this->assertFalse(
            $this->ownership(new FakeDnsProbe)->verify('nothing-here.com', 'tok-123', ['shops.talivio.com']),
        );
    }

    /** The product shows this to the customer, so it is part of the contract. */
    public function test_the_token_host_is_the_verify_subdomain(): void
    {
        $this->assertSame(
            '_talivio-verify.acme.com',
            $this->ownership(new FakeDnsProbe)->tokenHost('  ACME.com '),
        );
    }
}
