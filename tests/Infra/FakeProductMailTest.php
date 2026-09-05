<?php

namespace Talivio\Sdk\Tests\Infra;

use PHPUnit\Framework\TestCase;
use Talivio\Sdk\Infra\Exceptions\HostRefusedException;
use Talivio\Sdk\Infra\Testing\FakeProductMail;

/**
 * A double that is more permissive than production is worse than no
 * double: the product's tests go green on a flow the gateway refuses.
 * These pin the rules FakeProductMail must keep enforcing.
 */
class FakeProductMailTest extends TestCase
{
    public function test_a_domain_must_be_verified_before_it_can_hold_addresses(): void
    {
        $mail = new FakeProductMail;
        $mail->createDomain('site-1', 'acme.com');

        try {
            $mail->createMailbox('acme.com', 'info', 'correct-horse-battery');
            $this->fail('An unverified domain must not accept a mailbox.');
        } catch (HostRefusedException $e) {
            $this->assertStringContainsString('not verified', $e->reason());
        }

        $mail->markVerified('acme.com');
        $mail->createMailbox('acme.com', 'info', 'correct-horse-battery');

        $this->assertArrayHasKey('info@acme.com', $mail->mailboxes);
    }

    public function test_a_domain_someone_else_holds_cannot_be_created(): void
    {
        $mail = new FakeProductMail;
        $mail->takenElsewhere = ['kangurular.example'];

        $this->expectException(HostRefusedException::class);

        $mail->createDomain('site-1', 'kangurular.example');
    }

    /**
     * The gateway answers the same way for "not yours" and "does not
     * exist"; the fake must not be more informative.
     */
    public function test_an_unknown_domain_is_refused_the_same_way_as_someone_elses(): void
    {
        $mail = new FakeProductMail;

        try {
            $mail->dnsRecords('nothere.com');
            $this->fail('An unknown domain must be refused.');
        } catch (HostRefusedException $e) {
            $this->assertSame('Domain not found or unauthorized.', $e->reason());
        }
    }

    public function test_the_ownership_record_leads_the_guide_until_verified(): void
    {
        $mail = new FakeProductMail;
        $mail->createDomain('site-1', 'acme.com');

        $this->assertTrue($mail->dnsRecords('acme.com')[0]['is_verification']);

        $mail->markVerified('acme.com');

        $this->assertSame('MX', $mail->dnsRecords('acme.com')[0]['type']);
    }

    public function test_deleting_a_domain_takes_its_addresses_with_it(): void
    {
        $mail = new FakeProductMail;
        $mail->createDomain('site-1', 'acme.com');
        $mail->markVerified('acme.com');
        $mail->createMailbox('acme.com', 'info', 'correct-horse-battery');
        $mail->createAlias('hello@acme.com', 'me@gmail.com');

        $mail->deleteDomain('acme.com');

        $this->assertSame([], $mail->mailboxes);
        $this->assertSame([], $mail->aliases);
        $this->assertSame(['acme.com'], $mail->deletedDomains);
    }

    public function test_usage_only_counts_the_named_customers_domains(): void
    {
        $mail = new FakeProductMail;

        foreach ([['site-1', 'one.com'], ['site-2', 'two.com']] as [$ref, $domain]) {
            $mail->createDomain($ref, $domain);
            $mail->markVerified($domain);
            $mail->createMailbox($domain, 'info', 'correct-horse-battery');
        }

        $this->assertSame(2, $mail->usage()['mailboxes']);
        $this->assertSame(1, $mail->usage('site-1')['mailboxes']);
    }
}
