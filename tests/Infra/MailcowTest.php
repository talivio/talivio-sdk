<?php

namespace Talivio\Sdk\Tests\Infra;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Talivio\Sdk\Infra\Clients\Mailcow;
use Talivio\Sdk\Infra\Contracts\Mail;
use Talivio\Sdk\Infra\Exceptions\HostRefusedException;
use Talivio\Sdk\Infra\Exceptions\NotConfiguredException;
use Talivio\Sdk\Infra\Support\MailOwner;
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

        try {
            $this->mail()->addMailbox('myshop.com', 'info', 'short');
            $this->fail('A refused write must throw.');
        } catch (HostRefusedException $e) {
            // The host's own wording is what the customer needs to read.
            $this->assertSame('password_complexity min 8 chars', $e->reason());
        }
    }

    /**
     * A refusal and an outage need opposite handling: one carries a reason
     * worth showing and will never succeed on retry, the other is "try
     * again in a moment". Only the refusal is a HostRefusedException.
     */
    public function test_an_outage_is_not_a_refusal(): void
    {
        Http::fake([self::API.'/add/mailbox' => Http::response('gateway down', 502)]);

        try {
            $this->mail()->addMailbox('myshop.com', 'info', 'correct-horse-battery');
            $this->fail('An unreachable host must throw.');
        } catch (RuntimeException $e) {
            $this->assertNotInstanceOf(HostRefusedException::class, $e);
            $this->assertStringContainsString('HTTP 502', $e->getMessage());
        }
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

    // ------------------------------------------------------------------
    // Owner-of-record surface (Mailio)
    // ------------------------------------------------------------------

    /**
     * A domain nobody has proven ownership of must never accept mail —
     * mailcow treats a local domain as authoritative and would swallow
     * mail belonging to its real owner.
     */
    public function test_a_domain_can_be_created_switched_off_and_carries_its_owner_tag(): void
    {
        Http::fake([
            self::API.'/get/domain/myshop.com' => Http::response([]),
            self::API.'/add/domain' => Http::response([['type' => 'success', 'msg' => 'domain_added']]),
        ]);

        $this->mail()->addDomain(
            'MyShop.com',
            maxMailboxes: 50,
            maxQuotaMb: 10240,
            active: false,
            owner: new MailOwner('mailio', 'company-7', 'Acme Ltd'),
            defaultQuotaMb: 2048,
            totalQuotaMb: 51200,
            maxAliases: 25,
        );

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/add/domain')
            && $request['domain'] === 'myshop.com'
            && $request['description'] === 'Acme Ltd [mailio:company-7]'
            && $request['active'] === 0
            && $request['mailboxes'] === 50
            && $request['defquota'] === 2048
            && $request['maxquota'] === 10240
            && $request['quota'] === 51200
            && $request['aliases'] === 25);
    }

    public function test_set_domain_active_toggles_the_domain(): void
    {
        Http::fake([self::API.'/edit/domain' => Http::response([['type' => 'success', 'msg' => 'domain_modified']])]);

        $this->mail()->setDomainActive('myshop.com', true);

        Http::assertSent(fn (Request $request) => $request['items'] === ['myshop.com'] && $request['attr'] === ['active' => '1']);
    }

    public function test_domain_returns_null_for_a_domain_the_host_does_not_have(): void
    {
        Http::fake([
            self::API.'/get/domain/known.com' => Http::response(['domain_name' => 'known.com', 'active' => 1]),
            self::API.'/get/domain/unknown.com' => Http::response([]),
        ]);

        $this->assertSame('known.com', $this->mail()->domain('known.com')['domain_name']);
        $this->assertNull($this->mail()->domain('unknown.com'));
    }

    public function test_list_domains_returns_the_whole_instance(): void
    {
        Http::fake([self::API.'/get/domain/all' => Http::response([
            ['domain_name' => 'a.com', 'description' => 'Acme [mailio:company-7]'],
            ['domain_name' => 'b.com', 'description' => 'Contentio'],
        ])]);

        $rows = $this->mail()->listDomains();

        $this->assertCount(2, $rows);
        $this->assertSame('company-7', MailOwner::fromDescription($rows[0]['description'])->ref);
        $this->assertNull(MailOwner::fromDescription($rows[1]['description']), 'A legacy description has no owner tag.');
    }

    public function test_update_mailbox_sends_only_the_changed_attributes_and_doubles_the_password(): void
    {
        Http::fake([self::API.'/edit/mailbox' => Http::response([['type' => 'success', 'msg' => 'mailbox_modified']])]);

        $mail = $this->mail();
        $mail->updateMailbox('info@myshop.com', ['quota_mb' => 5120, 'active' => false]);

        Http::assertSent(fn (Request $request) => $request['items'] === ['info@myshop.com']
            && $request['attr'] === ['quota' => 5120, 'active' => '0']);

        $mail->updateMailbox('info@myshop.com', ['password' => 'correct-horse-battery']);

        Http::assertSent(fn (Request $request) => ($request['attr']['password'] ?? null) === 'correct-horse-battery'
            && ($request['attr']['password2'] ?? null) === 'correct-horse-battery');
    }

    /**
     * The list is replaced wholesale, so an empty array is a real change
     * ("stop forwarding") and must not be skipped the way an absent key is.
     */
    public function test_forwarding_is_replaced_wholesale_and_can_be_cleared(): void
    {
        Http::fake([self::API.'/edit/mailbox' => Http::response([['type' => 'success', 'msg' => 'mailbox_modified']])]);

        $mail = $this->mail();
        $mail->updateMailbox('info@myshop.com', ['forward_to' => ['a@x.com', 'b@x.com'], 'forward_only' => true]);

        Http::assertSent(fn (Request $request) => $request['attr'] === ['forward_to' => 'a@x.com,b@x.com', 'forward_only' => '1']);

        $mail->updateMailbox('info@myshop.com', ['forward_to' => []]);

        Http::assertSent(fn (Request $request) => $request['attr'] === ['forward_to' => '']);
    }

    public function test_an_empty_change_set_sends_nothing(): void
    {
        Http::fake();

        $this->mail()->updateMailbox('info@myshop.com', []);

        Http::assertNothingSent();
    }

    /**
     * A billing webhook suspends a whole account; doing it per mailbox is
     * a round trip each inside a request that has to answer fast.
     */
    public function test_bulk_suspend_sends_one_call_for_every_mailbox(): void
    {
        Http::fake([self::API.'/edit/mailbox' => Http::response([['type' => 'success', 'msg' => 'mailbox_modified']])]);

        $this->mail()->setMailboxesActive(['a@x.com', 'B@x.com', '', 'c@x.com'], false);

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request['items'] === ['a@x.com', 'b@x.com', 'c@x.com']
            && $request['attr'] === ['active' => '0']);
    }

    public function test_bulk_suspend_of_an_empty_list_sends_nothing(): void
    {
        Http::fake();

        $this->mail()->setMailboxesActive([], false);

        Http::assertNothingSent();
    }

    public function test_mailbox_quota_reports_bytes_and_a_percentage(): void
    {
        Http::fake([
            self::API.'/get/mailbox/info@myshop.com' => Http::response([
                'username' => 'info@myshop.com', 'quota' => 1000, 'quota_used' => 250,
            ]),
            self::API.'/get/mailbox/gone@myshop.com' => Http::response([]),
        ]);

        $this->assertSame(['used' => 250, 'total' => 1000, 'percent' => 25.0], $this->mail()->mailboxQuota('info@myshop.com'));
        // A dashboard listing a stale row must render, not blow up.
        $this->assertSame(['used' => 0, 'total' => 0, 'percent' => 0.0], $this->mail()->mailboxQuota('gone@myshop.com'));
    }

    public function test_resource_summary_aggregates_across_domains(): void
    {
        Http::fake([
            self::API.'/get/mailbox/all/a.com' => Http::response([
                ['username' => 'x@a.com', 'quota' => 1000, 'quota_used' => 400],
                ['username' => 'y@a.com', 'quota' => 1000, 'quota_used' => 100],
            ]),
            self::API.'/get/mailbox/all/b.com' => Http::response([['username' => 'z@b.com', 'quota' => 2000, 'quota_used' => 500]]),
            self::API.'/get/alias/all/a.com' => Http::response([['id' => 1, 'address' => 'hi@a.com']]),
            self::API.'/get/alias/all/b.com' => Http::response([]),
        ]);

        $this->assertSame([
            'mailboxes' => 3,
            'aliases' => 1,
            'used_bytes' => 1000,
            'quota_bytes' => 4000,
            'usage_percent' => 25.0,
        ], $this->mail()->resourceSummary(['a.com', 'b.com']));
    }

    public function test_count_aliases_asks_the_host_per_domain(): void
    {
        Http::fake([
            self::API.'/get/alias/all/a.com' => Http::response([['id' => 1], ['id' => 2]]),
            self::API.'/get/alias/all/b.com' => Http::response([['id' => 3]]),
        ]);

        $this->assertSame(3, $this->mail()->countAliases(['a.com', 'b.com']));
    }

    public function test_an_alias_can_be_deleted_by_id_without_a_lookup(): void
    {
        Http::fake([self::API.'/delete/alias' => Http::response([['type' => 'success', 'msg' => 'alias_removed']])]);

        $this->mail()->deleteAliasById(9);

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request->data() === ['9']);
    }

    public function test_update_alias_edits_by_id(): void
    {
        Http::fake([self::API.'/edit/alias' => Http::response([['type' => 'success', 'msg' => 'alias_modified']])]);

        $this->mail()->updateAlias(9, ['goto' => 'new@gmail.com', 'active' => true]);

        Http::assertSent(fn (Request $request) => $request['items'] === ['9']
            && $request['attr'] === ['goto' => 'new@gmail.com', 'active' => '1']);
    }

    public function test_sync_jobs_are_listed_created_and_deleted(): void
    {
        Http::fake([
            self::API.'/get/syncjobs/all/no_passwords' => Http::response([['id' => 3, 'user2' => 'info@myshop.com']]),
            self::API.'/add/syncjob' => Http::response([['type' => 'success', 'msg' => 'syncjob_added']]),
            self::API.'/delete/syncjob' => Http::response([['type' => 'success', 'msg' => 'syncjob_removed']]),
        ]);

        $mail = $this->mail();

        $this->assertSame([['id' => 3, 'user2' => 'info@myshop.com']], $mail->listSyncJobs());

        $mail->addSyncJob(['username' => 'info@myshop.com', 'host1' => 'imap.old.com']);
        $mail->deleteSyncJob(3);

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/add/syncjob') && $request['host1'] === 'imap.old.com');
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/delete/syncjob') && $request->data() === ['3']);
    }
}
