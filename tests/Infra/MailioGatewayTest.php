<?php

namespace Talivio\Sdk\Tests\Infra;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Talivio\Sdk\Infra\Clients\MailioGateway;
use Talivio\Sdk\Infra\Contracts\ProductMail;
use Talivio\Sdk\Infra\Exceptions\HostRefusedException;
use Talivio\Sdk\Infra\Exceptions\NotConfiguredException;
use Talivio\Sdk\Infra\Support\UnconfiguredProductMail;
use Talivio\Sdk\Tests\TestCase;

class MailioGatewayTest extends TestCase
{
    protected const API = 'mailio.talivio.test/api/v1/mail';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'talivio.infra.mail_gateway.base_url' => 'https://mailio.talivio.test/api/v1/mail',
            'talivio.infra.mail_gateway.key' => 'tmail_test_key',
        ]);
    }

    protected function gateway(): MailioGateway
    {
        return MailioGateway::fromConfig() ?? $this->fail('The gateway should be configured for this test.');
    }

    public function test_the_container_resolves_the_gateway_as_the_product_mail_contract(): void
    {
        $this->assertInstanceOf(MailioGateway::class, $this->app->make(ProductMail::class));
    }

    public function test_an_unconfigured_gateway_resolves_but_fails_on_use(): void
    {
        config(['talivio.infra.mail_gateway.key' => null]);

        $this->assertNull(MailioGateway::fromConfig());

        $mail = $this->app->make(ProductMail::class);
        $this->assertInstanceOf(UnconfiguredProductMail::class, $mail);

        $this->expectException(NotConfiguredException::class);
        $this->expectExceptionMessage('TALIVIO_MAIL_KEY');

        $mail->domains();
    }

    /**
     * The key is the identity. There is no product parameter anywhere in
     * the surface, so a product cannot claim to be another one.
     */
    public function test_every_call_carries_the_key_as_a_bearer_token(): void
    {
        Http::fake([self::API.'/domains*' => Http::response(['data' => []])]);

        $this->gateway()->domains();

        Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer tmail_test_key'));
    }

    public function test_create_domain_sends_the_customer_ref_and_the_sold_ceilings(): void
    {
        Http::fake([self::API.'/domains' => Http::response(['data' => [
            'domain' => 'acme.com', 'customer_ref' => 'site-42', 'verified' => false,
            'verification_token' => 'mailio-verify=x', 'max_mailboxes' => 5, 'default_quota_mb' => 2048,
        ]], 201)]);

        $domain = $this->gateway()->createDomain('site-42', 'Acme.com', [
            'label' => 'Acme Ltd', 'mailboxes' => 5, 'quota_mb' => 2048,
        ]);

        $this->assertSame('acme.com', $domain['domain']);
        $this->assertFalse($domain['verified']);

        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request['customer_ref'] === 'site-42'
            && $request['domain'] === 'acme.com'
            && $request['label'] === 'Acme Ltd'
            && $request['mailboxes'] === 5
            && $request['quota_mb'] === 2048
            && ! isset($request['total_quota_mb']));
    }

    /**
     * "Taken" is a business answer the customer should read, and Mailio
     * deliberately does not say whose it is.
     */
    public function test_a_refusal_carries_mailios_wording(): void
    {
        Http::fake([self::API.'/domains' => Http::response(['error' => 'This domain is already registered in the system.'], 409)]);

        try {
            $this->gateway()->createDomain('site-1', 'taken.com');
            $this->fail('A refused create must throw.');
        } catch (HostRefusedException $e) {
            $this->assertSame('This domain is already registered in the system.', $e->reason());
        }
    }

    /**
     * An outage is not a refusal: retrying may help and the transport
     * detail must not reach the customer.
     */
    public function test_a_server_error_is_an_outage_not_a_refusal(): void
    {
        Http::fake([self::API.'/domains*' => Http::response('gateway down', 502)]);

        try {
            $this->gateway()->domains();
            $this->fail('An unreachable gateway must throw.');
        } catch (RuntimeException $e) {
            $this->assertNotInstanceOf(HostRefusedException::class, $e);
            $this->assertStringContainsString('HTTP 502', $e->getMessage());
        }
    }

    public function test_an_unauthorised_key_is_a_refusal_the_product_can_read(): void
    {
        Http::fake([self::API.'/domains*' => Http::response(['error' => 'Unauthenticated.'], 401)]);

        $this->expectException(HostRefusedException::class);

        $this->gateway()->domains();
    }

    /**
     * An alias address contains "@", which would otherwise be read as
     * userinfo in the URL.
     */
    public function test_an_address_is_encoded_into_the_path(): void
    {
        Http::fake([self::API.'/aliases/*' => Http::response(['data' => ['deleted' => true]])]);

        $this->gateway()->deleteAlias('hello@acme.com');

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/aliases/hello%40acme.com')
            && $request->method() === 'DELETE');
    }

    public function test_dns_records_come_back_ready_to_show(): void
    {
        Http::fake([self::API.'/domains/acme.com/dns' => Http::response(['data' => [
            ['type' => 'TXT', 'name' => 'acme.com', 'value' => 'mailio-verify=x', 'description' => 'Ownership', 'is_verification' => true],
            ['type' => 'MX', 'name' => 'acme.com', 'value' => 'mail.talivio.com', 'priority' => 10, 'description' => 'Mail exchange', 'is_verification' => false],
        ]])]);

        $records = $this->gateway()->dnsRecords('acme.com');

        $this->assertTrue($records[0]['is_verification']);
        $this->assertSame(10, $records[1]['priority']);
    }

    public function test_a_mailbox_is_created_and_deleted(): void
    {
        Http::fake([
            self::API.'/mailboxes' => Http::response(['data' => ['address' => 'info@acme.com', 'name' => 'Info', 'quota_mb' => 1024]], 201),
            self::API.'/mailboxes/*' => Http::response(['data' => ['deleted' => true]]),
        ]);

        $mailbox = $this->gateway()->createMailbox('acme.com', 'Info', 'correct-horse-battery', 'Info');
        $this->assertSame('info@acme.com', $mailbox['address']);

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/mailboxes')
            && $request['local_part'] === 'info'
            && $request['password'] === 'correct-horse-battery');

        $this->gateway()->deleteMailbox('info@acme.com');

        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE'
            && str_ends_with($request->url(), '/mailboxes/info%40acme.com'));
    }

    /**
     * A resent write could charge a plan slot twice or resurrect an address
     * the customer just deleted, so only reads are retried.
     */
    public function test_reads_are_retried_but_writes_are_not(): void
    {
        $reads = 0;
        $writes = 0;

        Http::fake([
            self::API.'/usage*' => function () use (&$reads) {
                return Http::response('', ++$reads < 3 ? 500 : 200);
            },
            self::API.'/mailboxes' => function () use (&$writes) {
                $writes++;

                return Http::response('', 500);
            },
        ]);

        $this->gateway()->usage();
        $this->assertSame(3, $reads, 'A read retries past a transient 5xx.');

        try {
            $this->gateway()->createMailbox('acme.com', 'info', 'correct-horse-battery');
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame(1, $writes, 'A write is attempted exactly once.');
    }
}
