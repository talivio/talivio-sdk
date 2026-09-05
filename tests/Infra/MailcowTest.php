<?php

namespace Talivio\Sdk\Tests\Infra;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Talivio\Sdk\Infra\Clients\Mailcow;
use Talivio\Sdk\Infra\Contracts\Mail;
use Talivio\Sdk\Infra\Exceptions\NotConfiguredException;
use Talivio\Sdk\Infra\Support\UnconfiguredMail;
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

    public function test_an_unconfigured_mail_host_resolves_but_fails_on_use(): void
    {
        config(['talivio.infra.mailcow.api_key' => null]);

        $this->assertNull(Mailcow::fromConfig());

        $mail = $this->app->make(Mail::class);

        $this->assertInstanceOf(UnconfiguredMail::class, $mail);

        $this->expectException(NotConfiguredException::class);
        $this->expectExceptionMessage('MAILCOW_API_KEY');

        $mail->addDomain('myshop.com');
    }

    public function test_add_domain_sends_the_api_key_header_and_the_quota_shape(): void
    {
        Http::fake([
            self::API.'/get/domain/myshop.com' => Http::response([]),
            self::API.'/add/domain' => Http::response([['type' => 'success', 'msg' => ['domain_added', 'myshop.com']]]),
        ]);

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
     * add/domain on a domain mailcow already has is a "danger" reply, not
     * a no-op — so the client looks first and skips the add.
     */
    public function test_add_domain_is_a_no_op_for_a_domain_mailcow_already_has(): void
    {
        Http::fake([self::API.'/get/domain/myshop.com' => Http::response(['domain_name' => 'myshop.com', 'active' => 1])]);

        $this->mail()->addDomain('myshop.com');

        Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '/add/domain'));
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
            ['type' => 'TXT', 'name' => '_dmarc.myshop.com', 'content' => 'v=DMARC1; p=quarantine; rua=mailto:postmaster@myshop.com'],
            ['type' => 'TXT', 'name' => 'dkim._domainkey.myshop.com', 'content' => 'v=DKIM1;k=rsa;p=ABC'],
        ], $this->mail()->dnsRecords('MyShop.com'));
    }

    public function test_dns_records_leave_out_what_is_not_configured(): void
    {
        config(['talivio.infra.mailcow.mx_host' => null, 'talivio.infra.mailcow.spf_value' => null]);
        Http::fake([self::API.'/get/dkim/myshop.com' => Http::response([])]);

        $this->assertSame(['_dmarc.myshop.com'], array_column($this->mail()->dnsRecords('myshop.com'), 'name'));
    }

    /**
     * An alias's mailcow primary key is its numeric id, not its address
     * (a mailbox's IS its address) — confirmed live in Shops 2026-08-30.
     */
    public function test_delete_alias_looks_the_id_up_first_and_is_idempotent(): void
    {
        Http::fake([
            self::API.'/get/alias/all/myshop.com' => Http::response([
                ['id' => 9, 'address' => 'hello@myshop.com', 'goto' => 'me@gmail.com'],
            ]),
            self::API.'/delete/alias' => Http::response([['type' => 'success', 'msg' => 'alias_removed']]),
        ]);

        $mail = $this->mail();
        $mail->deleteAlias('Hello@myshop.com');
        $mail->deleteAlias('gone@myshop.com');

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/delete/alias') && $request->data() === ['9']);
        Http::assertSentCount(3); // lookup, delete, lookup (nothing to delete)
    }

    public function test_delete_domain_treats_an_unknown_domain_as_success(): void
    {
        // Http::fake() stubs accumulate (first match wins), so one
        // callback answers per domain instead of three fakes.
        Http::fake([self::API.'/delete/domain' => fn (Request $request) => match ($request->data()[0]) {
            'myshop.com' => Http::response([['type' => 'success', 'msg' => ['domain_removed', 'myshop.com']]]),
            'gone.com' => Http::response([['type' => 'danger', 'msg' => 'Domain does not exist']]),
            default => Http::response([['type' => 'danger', 'msg' => 'access_denied']]),
        }]);

        $mail = $this->mail();
        $mail->deleteDomain('myshop.com');
        $mail->deleteDomain('gone.com');

        Http::assertSent(fn (Request $request) => $request->data() === ['myshop.com']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('access_denied');

        $mail->deleteDomain('locked.com');
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

    /** A mailbox's mailcow primary key IS its address — no lookup needed. */
    public function test_delete_mailbox_posts_the_address_as_a_bare_list(): void
    {
        Http::fake([self::API.'/delete/mailbox' => Http::response([['type' => 'success', 'msg' => 'mailbox_removed']])]);

        $this->mail()->deleteMailbox('info@myshop.com');

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/delete/mailbox') && $request->data() === ['info@myshop.com']);
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
