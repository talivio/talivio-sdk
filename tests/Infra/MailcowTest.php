<?php

namespace Talivio\Sdk\Tests\Infra;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Talivio\Sdk\Infra\Clients\Mailcow;
use Talivio\Sdk\Infra\Contracts\Mail;
use Talivio\Sdk\Infra\Exceptions\NotConfiguredException;
use Talivio\Sdk\Tests\TestCase;

class MailcowTest extends TestCase
{
    protected const API = 'mail.talivio.test/api/v1';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'talivio.infra.mailcow.url' => 'https://mail.talivio.test/',
            'talivio.infra.mailcow.api_key' => 'mc_key',
            'talivio.infra.mailcow.mx_host' => 'mail.talivio.test',
            'talivio.infra.mailcow.spf_value' => 'v=spf1 mx -all',
            'talivio.infra.mailcow.description' => 'Contentio',
        ]);
    }

    protected function mail(): Mailcow
    {
        return Mailcow::fromConfig() ?? $this->fail('mailcow should be configured for this test.');
    }

    public function test_the_container_resolves_mailcow_as_the_mail_host(): void
    {
        $this->assertInstanceOf(Mailcow::class, $this->app->make(Mail::class));
    }

    public function test_an_unconfigured_mail_host_fails_at_resolution(): void
    {
        config(['talivio.infra.mailcow.api_key' => null]);

        $this->assertNull(Mailcow::fromConfig());

        $this->expectException(NotConfiguredException::class);
        $this->expectExceptionMessage('MAILCOW_API_KEY');

        $this->app->make(Mail::class);
    }

    public function test_add_domain_sends_the_api_key_header_and_the_quota_shape(): void
    {
        Http::fake([self::API.'/add/domain' => Http::response([['type' => 'success', 'msg' => ['domain_added', 'myshop.com']]])]);

        $this->mail()->addDomain('MyShop.com', maxMailboxes: 5, maxQuotaMb: 2048);

        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request->hasHeader('X-API-Key', 'mc_key')
            && $request->url() === 'https://mail.talivio.test/api/v1/add/domain'
            && $request['domain'] === 'myshop.com'
            && $request['description'] === 'Contentio'
            && $request['mailboxes'] === 5
            && $request['maxquota'] === 2048
            && $request['active'] === 1);
    }

    /**
     * mailcow answers 200 for a failed write — the error lives in the body.
     */
    public function test_a_failed_write_is_detected_from_the_body_not_the_status(): void
    {
        Http::fake([self::API.'/add/mailbox' => Http::response([['type' => 'danger', 'msg' => ['password_complexity', 'min 8 chars']]], 200)]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('password_complexity min 8 chars');

        $this->mail()->addMailbox('myshop.com', 'info', 'short');
    }

    public function test_an_http_failure_is_reported_with_its_status(): void
    {
        Http::fake([self::API.'/get/dkim/*' => Http::response('Forbidden', 403)]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('HTTP 403');

        $this->mail()->dkim('myshop.com');
    }

    public function test_dkim_reads_the_selector_back_rather_than_assuming_it(): void
    {
        Http::fake([self::API.'/get/dkim/myshop.com' => Http::response(['dkim_selector' => 'mail', 'dkim_txt' => 'v=DKIM1;k=rsa;p=ABC'])]);

        $this->assertSame(['selector' => 'mail', 'record' => 'v=DKIM1;k=rsa;p=ABC'], $this->mail()->dkim('myshop.com'));
    }

    public function test_dkim_is_null_when_the_host_has_no_key_yet(): void
    {
        Http::fake([self::API.'/get/dkim/myshop.com' => Http::response([])]);

        $this->assertNull($this->mail()->dkim('myshop.com'));
    }

    public function test_dns_records_list_mx_spf_and_dkim(): void
    {
        Http::fake([self::API.'/get/dkim/myshop.com' => Http::response(['dkim_selector' => 'dkim', 'dkim_txt' => 'v=DKIM1;k=rsa;p=ABC'])]);

        $this->assertSame([
            ['type' => 'MX', 'name' => 'myshop.com', 'content' => 'mail.talivio.test', 'priority' => 10],
            ['type' => 'TXT', 'name' => 'myshop.com', 'content' => 'v=spf1 mx -all'],
            ['type' => 'TXT', 'name' => 'dkim._domainkey.myshop.com', 'content' => 'v=DKIM1;k=rsa;p=ABC'],
        ], $this->mail()->dnsRecords('MyShop.com'));
    }

    public function test_dns_records_leave_out_what_is_not_configured(): void
    {
        config(['talivio.infra.mailcow.mx_host' => null, 'talivio.infra.mailcow.spf_value' => null]);
        Http::fake([self::API.'/get/dkim/myshop.com' => Http::response([])]);

        $this->assertSame([], $this->mail()->dnsRecords('myshop.com'));
    }

    public function test_add_mailbox_sends_the_password_twice_as_mailcow_wants(): void
    {
        Http::fake([self::API.'/add/mailbox' => Http::response([['type' => 'success', 'msg' => 'mailbox_added']])]);

        $this->mail()->addMailbox('myshop.com', 'info', 'correct horse battery', 'Info');

        Http::assertSent(fn (Request $request) => $request['local_part'] === 'info'
            && $request['domain'] === 'myshop.com'
            && $request['name'] === 'Info'
            && $request['password'] === 'correct horse battery'
            && $request['password2'] === 'correct horse battery');
    }

    public function test_deletes_post_the_address_as_a_bare_list(): void
    {
        Http::fake([
            self::API.'/delete/mailbox' => Http::response([['type' => 'success', 'msg' => 'mailbox_removed']]),
            self::API.'/delete/alias' => Http::response([['type' => 'success', 'msg' => 'alias_removed']]),
        ]);

        $this->mail()->deleteMailbox('info@myshop.com');
        $this->mail()->deleteAlias('hello@myshop.com');

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/delete/mailbox') && $request->data() === ['info@myshop.com']);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/delete/alias') && $request->data() === ['hello@myshop.com']);
    }

    public function test_lists_return_the_hosts_rows(): void
    {
        Http::fake([
            self::API.'/get/mailbox/all/myshop.com' => Http::response([['username' => 'info@myshop.com']]),
            self::API.'/get/alias/all/myshop.com' => Http::response([['address' => 'hello@myshop.com', 'goto' => 'me@gmail.com']]),
        ]);

        $this->assertSame([['username' => 'info@myshop.com']], $this->mail()->listMailboxes('myshop.com'));
        $this->assertSame([['address' => 'hello@myshop.com', 'goto' => 'me@gmail.com']], $this->mail()->listAliases('myshop.com'));
    }

    public function test_add_alias_forwards_to_the_external_address(): void
    {
        Http::fake([self::API.'/add/alias' => Http::response([['type' => 'success', 'msg' => 'alias_added']])]);

        $this->mail()->addAlias('hello@myshop.com', 'me@gmail.com');

        Http::assertSent(fn (Request $request) => $request['address'] === 'hello@myshop.com' && $request['goto'] === 'me@gmail.com' && $request['active'] === 1);
    }
}
