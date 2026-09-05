<?php

namespace Talivio\Sdk\Tests\Infra;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Talivio\Sdk\Infra\Clients\Cloudflare;
use Talivio\Sdk\Infra\Contracts\Dns;
use Talivio\Sdk\Infra\Exceptions\NotConfiguredException;
use Talivio\Sdk\Infra\Support\UnconfiguredDns;
use Talivio\Sdk\Tests\TestCase;

class CloudflareTest extends TestCase
{
    protected const API = 'api.cloudflare.com/client/v4';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'talivio.infra.cloudflare.api_token' => 'cf_token',
            // Scoped-token (Bearer) mode; the global-key test flips this.
            'talivio.infra.cloudflare.api_key' => null,
            'talivio.infra.cloudflare.email' => null,
            'talivio.infra.cloudflare.account_id' => 'acct_1',
            'talivio.infra.cloudflare.proxied' => false,
        ]);
    }

    protected function provider(): Cloudflare
    {
        return Cloudflare::fromConfig() ?? $this->fail('Cloudflare should be configured for this test.');
    }

    protected function ok(mixed $result, array $extra = []): array
    {
        return ['success' => true, 'errors' => [], 'messages' => [], 'result' => $result] + $extra;
    }

    protected function zone(string $id = 'zone_1', string $name = 'myshop.com', string $status = 'pending'): array
    {
        return ['id' => $id, 'name' => $name, 'status' => $status, 'name_servers' => ['ada.ns.cloudflare.com', 'bob.ns.cloudflare.com']];
    }

    public function test_the_container_resolves_cloudflare_as_the_dns_provider(): void
    {
        $this->assertInstanceOf(Cloudflare::class, $this->app->make(Dns::class));
    }

    public function test_an_unconfigured_provider_resolves_but_fails_on_use(): void
    {
        config(['talivio.infra.cloudflare.api_token' => null]);

        $this->assertNull(Cloudflare::fromConfig());

        $dns = $this->app->make(Dns::class);

        $this->assertInstanceOf(UnconfiguredDns::class, $dns);
        $this->assertFalse($dns->verifyCredentials());

        $this->expectException(NotConfiguredException::class);
        $this->expectExceptionMessage('CLOUDFLARE_API_TOKEN');

        $dns->ensureZone('myshop.com');
    }

    public function test_ensure_zone_returns_an_existing_zone_without_creating_one(): void
    {
        Http::fake([
            self::API.'/zones?*' => Http::response($this->ok([$this->zone(status: 'active')])),
        ]);

        $zone = $this->provider()->ensureZone('MyShop.com');

        $this->assertSame(['id' => 'zone_1', 'nameservers' => ['ada.ns.cloudflare.com', 'bob.ns.cloudflare.com'], 'active' => true], $zone);
        Http::assertSent(fn (Request $request) => $request->method() === 'GET'
            && $request['name'] === 'myshop.com'
            && $request['account.id'] === 'acct_1'
            && $request->hasHeader('Authorization', 'Bearer cf_token'));
        Http::assertNotSent(fn (Request $request) => $request->method() === 'POST');
    }

    public function test_a_global_api_key_is_sent_with_the_account_email_instead_of_a_bearer(): void
    {
        config(['talivio.infra.cloudflare.api_token' => null, 'talivio.infra.cloudflare.api_key' => 'global_key', 'talivio.infra.cloudflare.email' => 'owner@example.com']);

        Http::fake([
            self::API.'/zones?*' => Http::response($this->ok([$this->zone()])),
        ]);

        $this->provider()->ensureZone('myshop.com');

        Http::assertSent(fn (Request $request) => $request->hasHeader('X-Auth-Email', 'owner@example.com')
            && $request->hasHeader('X-Auth-Key', 'global_key')
            && ! $request->hasHeader('Authorization'));
    }

    public function test_the_token_wins_when_both_auth_modes_are_configured(): void
    {
        config(['talivio.infra.cloudflare.api_key' => 'global_key', 'talivio.infra.cloudflare.email' => 'owner@example.com']);

        Http::fake([self::API.'/zones?*' => Http::response($this->ok([$this->zone()]))]);

        $this->provider()->ensureZone('myshop.com');

        Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer cf_token') && ! $request->hasHeader('X-Auth-Key'));
    }

    public function test_ensure_zone_defaults_to_no_jump_start(): void
    {
        Http::fake([
            self::API.'/zones?*' => Http::response($this->ok([])),
            self::API.'/zones' => Http::response($this->ok($this->zone('zone_new'))),
        ]);

        $this->provider()->ensureZone('myshop.com');

        Http::assertSent(fn (Request $request) => $request->method() === 'POST' && $request['jump_start'] === false);
    }

    /**
     * A customer delegating an ALREADY-LIVE domain's nameservers to us
     * needs whatever DNS it already has (email, other subdomains)
     * imported, or the switch breaks it outright — unlike a fresh Talivio
     * registration, which has nothing yet to import.
     */
    public function test_ensure_zone_requests_jump_start_when_asked(): void
    {
        Http::fake([
            self::API.'/zones?*' => Http::response($this->ok([])),
            self::API.'/zones' => Http::response($this->ok($this->zone('zone_new'))),
        ]);

        $this->provider()->ensureZone('myshop.com', jumpStart: true);

        Http::assertSent(fn (Request $request) => $request->method() === 'POST' && $request['jump_start'] === true);
    }

    public function test_ensure_zone_creates_the_zone_when_missing_and_returns_its_nameservers(): void
    {
        Http::fake([
            self::API.'/zones?*' => Http::response($this->ok([])),
            self::API.'/zones' => Http::response($this->ok($this->zone('zone_new'))),
        ]);

        $zone = $this->provider()->ensureZone('myshop.com');

        $this->assertSame('zone_new', $zone['id']);
        $this->assertFalse($zone['active']);
        $this->assertSame(['ada.ns.cloudflare.com', 'bob.ns.cloudflare.com'], $zone['nameservers']);
        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request['name'] === 'myshop.com'
            && $request['account'] === ['id' => 'acct_1']
            && $request['type'] === 'full'
            && $request['jump_start'] === false);
    }

    public function test_ensure_zone_survives_a_creation_race(): void
    {
        $lookups = 0;

        Http::fake([
            self::API.'/zones?*' => function () use (&$lookups) {
                // First lookup: nothing yet; after the "already exists" the
                // zone is there.
                return Http::response($this->ok(++$lookups === 1 ? [] : [$this->zone('zone_raced')]));
            },
            self::API.'/zones' => Http::response(['success' => false, 'errors' => [['code' => 1061, 'message' => 'already exists']], 'result' => null], 400),
        ]);

        $this->assertSame('zone_raced', $this->provider()->ensureZone('myshop.com')['id']);
    }

    public function test_ensure_zone_needs_an_account_id(): void
    {
        config(['talivio.infra.cloudflare.account_id' => null]);
        Http::fake();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('CLOUDFLARE_ACCOUNT_ID');

        try {
            $this->provider()->ensureZone('myshop.com');
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_cloudflares_own_error_text_surfaces(): void
    {
        Http::fake([
            self::API.'/zones?*' => Http::response(['success' => false, 'errors' => [['code' => 10000, 'message' => 'Authentication error']], 'result' => null], 403),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Authentication error (#10000)');

        $this->provider()->ensureZone('myshop.com');
    }

    public function test_zone_is_active_reads_the_zone_status(): void
    {
        Http::fake([
            self::API.'/zones/zone_1' => Http::response($this->ok($this->zone(status: 'active'))),
            self::API.'/zones/zone_2' => Http::response($this->ok($this->zone('zone_2', status: 'pending'))),
        ]);

        $this->assertTrue($this->provider()->zoneIsActive('zone_1'));
        $this->assertFalse($this->provider()->zoneIsActive('zone_2'));
    }

    public function test_find_zone_id_walks_up_to_the_longest_matching_active_zone(): void
    {
        Http::fake([
            self::API.'/zones?name=shop.example.com*' => Http::response($this->ok([])),
            self::API.'/zones?name=example.com*' => Http::response($this->ok([$this->zone('zone_ex', 'example.com', 'active')])),
        ]);

        // Built with a customer's token — no account id needed.
        $this->assertSame('zone_ex', Cloudflare::withToken('customer_token')->findZoneId('shop.example.com'));

        Http::assertSent(fn (Request $request) => $request['name'] === 'shop.example.com' && $request['status'] === 'active'
            && $request->hasHeader('Authorization', 'Bearer customer_token'));
        Http::assertSent(fn (Request $request) => $request['name'] === 'example.com');
        Http::assertNotSent(fn (Request $request) => $request['name'] === 'com');
    }

    public function test_find_zone_id_is_null_when_the_credential_sees_no_matching_zone(): void
    {
        Http::fake([self::API.'/zones?*' => Http::response($this->ok([]))]);

        $this->assertNull($this->provider()->findZoneId('shop.example.com'));
    }

    public function test_ensure_records_creates_the_apex_a_and_www_cname_when_absent(): void
    {
        Http::fake([
            self::API.'/zones/zone_1/dns_records?*' => Http::response($this->ok([])),
            self::API.'/zones/zone_1/dns_records' => Http::response($this->ok(['id' => 'rec_new'])),
        ]);

        $this->provider()->ensureRecords('zone_1', 'myshop.com', '203.0.113.10');

        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request['type'] === 'A' && $request['name'] === 'myshop.com'
            && $request['content'] === '203.0.113.10' && $request['proxied'] === false && $request['ttl'] === 1);
        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request['type'] === 'CNAME' && $request['name'] === 'www.myshop.com' && $request['content'] === 'myshop.com');
    }

    public function test_ensure_records_leaves_a_matching_record_alone_and_patches_a_stale_one(): void
    {
        Http::fake([
            self::API.'/zones/zone_1/dns_records?type=A*' => Http::response($this->ok([
                ['id' => 'rec_a', 'type' => 'A', 'name' => 'myshop.com', 'content' => '198.51.100.9', 'proxied' => false],
            ])),
            self::API.'/zones/zone_1/dns_records?type=CNAME*' => Http::response($this->ok([
                ['id' => 'rec_www', 'type' => 'CNAME', 'name' => 'www.myshop.com', 'content' => 'myshop.com', 'proxied' => false],
            ])),
            self::API.'/zones/zone_1/dns_records/rec_a' => Http::response($this->ok(['id' => 'rec_a'])),
        ]);

        $this->provider()->ensureRecords('zone_1', 'myshop.com', '203.0.113.10');

        Http::assertSent(fn (Request $request) => $request->method() === 'PATCH'
            && str_ends_with($request->url(), '/dns_records/rec_a')
            && $request['content'] === '203.0.113.10');
        Http::assertNotSent(fn (Request $request) => $request->method() === 'POST');
        Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '/dns_records/rec_www'));
    }

    public function test_records_are_proxied_when_configured(): void
    {
        config(['talivio.infra.cloudflare.proxied' => true]);

        Http::fake([
            self::API.'/zones/zone_1/dns_records?*' => Http::response($this->ok([])),
            self::API.'/zones/zone_1/dns_records' => Http::response($this->ok(['id' => 'rec_new'])),
        ]);

        $this->provider()->ensureRecords('zone_1', 'myshop.com', '203.0.113.10');

        Http::assertSent(fn (Request $request) => $request->method() === 'POST' && $request['proxied'] === true);
    }

    public function test_upsert_record_writes_mx_priority_and_omits_proxied_when_not_given(): void
    {
        Http::fake([
            self::API.'/zones/zone_1/dns_records?*' => Http::response($this->ok([])),
            self::API.'/zones/zone_1/dns_records' => Http::response($this->ok(['id' => 'rec_mx'])),
        ]);

        $this->provider()->upsertRecord('zone_1', 'mx', 'MyShop.com', 'mail.talivio.com', priority: 10);

        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request['type'] === 'MX' && $request['name'] === 'myshop.com'
            && $request['content'] === 'mail.talivio.com' && $request['priority'] === 10
            && ! isset($request['proxied']));
    }

    public function test_upsert_record_updates_an_existing_txt_in_place(): void
    {
        Http::fake([
            self::API.'/zones/zone_1/dns_records?*' => Http::response($this->ok([
                ['id' => 'rec_txt', 'type' => 'TXT', 'name' => '_talivio-verify.myshop.com', 'content' => 'old-token'],
            ])),
            self::API.'/zones/zone_1/dns_records/rec_txt' => Http::response($this->ok(['id' => 'rec_txt'])),
        ]);

        $this->provider()->upsertRecord('zone_1', 'TXT', '_talivio-verify.myshop.com', 'new-token');

        Http::assertSent(fn (Request $request) => $request->method() === 'PATCH' && $request['content'] === 'new-token');
        Http::assertNotSent(fn (Request $request) => $request->method() === 'POST');
    }

    public function test_list_records_follows_pagination(): void
    {
        Http::fake([
            self::API.'/zones/zone_1/dns_records?*' => function (Request $request) {
                $page = (int) $request['page'];

                return Http::response($this->ok(
                    [['id' => "rec_{$page}", 'type' => 'A', 'name' => "p{$page}.myshop.com"]],
                    ['result_info' => ['total_pages' => 2]],
                ));
            },
        ]);

        $records = $this->provider()->listRecords('zone_1', 'myshop');

        $this->assertSame(['rec_1', 'rec_2'], array_column($records, 'id'));
        Http::assertSent(fn (Request $request) => $request['name.contains'] === 'myshop' && $request['per_page'] === 100);
    }

    public function test_delete_zone_treats_an_already_deleted_zone_as_success(): void
    {
        Http::fake([
            self::API.'/zones/zone_gone' => Http::response(['success' => false, 'errors' => [['code' => 7003, 'message' => 'Could not route']], 'result' => null], 404),
            self::API.'/zones/zone_1' => Http::response($this->ok(['id' => 'zone_1'])),
        ]);

        $this->provider()->deleteZone('zone_gone');
        $this->provider()->deleteZone('zone_1');

        Http::assertSentCount(2);
    }

    public function test_verify_credentials_checks_the_token_status(): void
    {
        Http::fake([
            self::API.'/user/tokens/verify' => Http::response($this->ok(['status' => 'active'])),
        ]);

        $this->assertTrue($this->provider()->verifyCredentials());
    }

    public function test_verify_credentials_is_false_for_a_rejected_token(): void
    {
        Http::fake([
            self::API.'/user/tokens/verify' => Http::response(['success' => false, 'errors' => [['code' => 1000, 'message' => 'Invalid API Token']], 'result' => null], 401),
        ]);

        $this->assertFalse(Cloudflare::withToken('bad')->verifyCredentials());
    }
}
