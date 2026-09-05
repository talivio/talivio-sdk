<?php

namespace Talivio\Sdk\Tests\Infra;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Talivio\Sdk\Infra\Clients\Ploi;
use Talivio\Sdk\Infra\Contracts\Host;
use Talivio\Sdk\Infra\Exceptions\NotConfiguredException;
use Talivio\Sdk\Tests\TestCase;

class PloiTest extends TestCase
{
    protected const SERVER = 'ploi.io/api/servers/11';

    protected const SITE = 'ploi.io/api/servers/11/sites/22';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        config([
            'talivio.infra.ploi.api_token' => 'ploi_token',
            'talivio.infra.ploi.server_id' => '11',
            'talivio.infra.ploi.site_id' => '22',
            'talivio.infra.ploi.attach_mode' => 'tenant',
            'talivio.infra.ploi.server_ip' => null,
            'talivio.infra.ploi.dns_credential_id' => null,
            'talivio.infra.cloudflare.api_token' => null,
        ]);
    }

    protected function host(): Ploi
    {
        return Ploi::fromConfig() ?? $this->fail('Ploi should be configured for this test.');
    }

    // ------------------------------------------------------------------
    // Wiring
    // ------------------------------------------------------------------

    public function test_the_container_resolves_ploi_as_the_host(): void
    {
        $this->assertInstanceOf(Ploi::class, $this->app->make(Host::class));
    }

    public function test_an_unconfigured_host_fails_at_resolution(): void
    {
        config(['talivio.infra.ploi.api_token' => null]);

        $this->expectException(NotConfiguredException::class);
        $this->expectExceptionMessage('PLOI_API_TOKEN');

        $this->app->make(Host::class);
    }

    public function test_the_site_id_is_only_required_for_platform_site_calls(): void
    {
        config(['talivio.infra.ploi.site_id' => null]);
        Http::fake([self::SERVER.'/sites?*' => Http::response(['data' => [], 'meta' => ['last_page' => 1]])]);

        $host = $this->host();

        $this->assertSame([], $host->listSites());

        $this->expectException(NotConfiguredException::class);
        $this->expectExceptionMessage('PLOI_SITE_ID');

        $host->attachDomain('myshop.com');
    }

    public function test_an_unknown_attach_mode_is_rejected(): void
    {
        config(['talivio.infra.ploi.attach_mode' => 'magic']);

        $this->expectException(\InvalidArgumentException::class);

        $this->host();
    }

    // ------------------------------------------------------------------
    // Server
    // ------------------------------------------------------------------

    public function test_server_ip_comes_from_the_server_record_and_is_cached(): void
    {
        Http::fake([self::SERVER => Http::response(['data' => ['id' => 11, 'ip_address' => '203.0.113.10']])]);

        $this->assertSame('203.0.113.10', $this->host()->serverIp());
        $this->assertSame('203.0.113.10', $this->host()->serverIp());

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer ploi_token'));
    }

    public function test_a_configured_server_ip_wins_over_the_lookup(): void
    {
        config(['talivio.infra.ploi.server_ip' => '198.51.100.7']);
        Http::fake();

        $this->assertSame('198.51.100.7', $this->host()->serverIp());
        Http::assertNothingSent();
    }

    // ------------------------------------------------------------------
    // Tenant mode (Shops)
    // ------------------------------------------------------------------

    public function test_attach_domain_adds_a_tenant_to_the_platform_site(): void
    {
        Http::fake([
            self::SITE.'/tenants' => fn (Request $request) => $request->method() === 'GET'
                ? Http::response(['data' => ['tenants' => ['other.com'], 'count' => 1, 'main' => 'shops.talivio.com']])
                : Http::response(['data' => ['tenants' => ['other.com', 'myshop.com'], 'count' => 2, 'main' => 'shops.talivio.com']]),
        ]);

        $this->host()->attachDomain('MyShop.com');

        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && str_ends_with($request->url(), '/tenants')
            && $request['tenants'] === ['myshop.com']);
    }

    public function test_attach_domain_is_a_no_op_for_an_existing_tenant(): void
    {
        Http::fake([
            self::SITE.'/tenants' => Http::response(['data' => ['tenants' => ['myshop.com'], 'count' => 1, 'main' => 'shops.talivio.com']]),
        ]);

        $this->host()->attachDomain('myshop.com');

        Http::assertNotSent(fn (Request $request) => $request->method() === 'POST');
    }

    public function test_request_certificate_targets_the_tenant_with_the_domains_and_webhook(): void
    {
        Http::fake([self::SITE.'/tenants/myshop.com/request-certificate' => Http::response('', 201)]);

        $this->host()->requestCertificate('myshop.com', ['www.myshop.com'], 'https://shops.talivio.com/hook?signature=x');

        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request['domains'] === 'myshop.com,www.myshop.com'
            && $request['webhook'] === 'https://shops.talivio.com/hook?signature=x');
    }

    public function test_dns_validation_hands_ploi_the_scoped_cloudflare_token(): void
    {
        config(['talivio.infra.cloudflare.api_token' => 'cf_scoped']);
        Http::fake([self::SITE.'/tenants/myshop.com/request-certificate' => Http::response('', 201)]);

        $this->host()->requestCertificate('myshop.com', ['www.myshop.com'], null, validateViaDns: true);

        Http::assertSent(fn (Request $request) => $request['additional'] === ['use_dns_provider' => true, 'provider' => 'cloudflare', 'secret' => 'cf_scoped']);
    }

    public function test_dns_validation_prefers_a_ploi_profile_credential(): void
    {
        config(['talivio.infra.ploi.dns_credential_id' => '42', 'talivio.infra.cloudflare.api_token' => 'cf_scoped']);
        Http::fake([self::SITE.'/tenants/myshop.com/request-certificate' => Http::response('', 201)]);

        $this->host()->requestCertificate('myshop.com', [], null, validateViaDns: true);

        Http::assertSent(fn (Request $request) => $request['additional'] === ['use_dns_provider' => true, 'use_from_profile' => true, 'credential' => 42]);
    }

    public function test_dns_validation_falls_back_to_http_with_only_a_global_api_key(): void
    {
        config(['talivio.infra.cloudflare.api_key' => 'global_key', 'talivio.infra.cloudflare.email' => 'me@example.com']);
        Http::fake([self::SITE.'/tenants/myshop.com/request-certificate' => Http::response('', 201)]);

        $this->host()->requestCertificate('myshop.com', [], null, validateViaDns: true);

        Http::assertSent(fn (Request $request) => ! isset($request['additional']));
    }

    public function test_http_validation_sends_no_additional_block(): void
    {
        config(['talivio.infra.cloudflare.api_token' => 'cf_scoped']);
        Http::fake([self::SITE.'/tenants/myshop.com/request-certificate' => Http::response('', 201)]);

        $this->host()->requestCertificate('myshop.com');

        Http::assertSent(fn (Request $request) => ! isset($request['additional']));
    }

    public function test_a_rejected_certificate_request_surfaces_plois_message(): void
    {
        Http::fake([self::SITE.'/tenants/myshop.com/request-certificate' => Http::response([
            'message' => 'The given data was invalid.',
            'errors' => ['domains' => ['myshop.com does not point to this server.']],
        ], 422)]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not point to this server');

        $this->host()->requestCertificate('myshop.com');
    }

    public function test_an_ip_allowlist_rejection_is_explained(): void
    {
        Http::fake([self::SITE.'/tenants' => Http::response(['message' => 'This IP address is not allowed.'], 403)]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('IP allowlist');

        $this->host()->attachDomain('myshop.com');
    }

    public function test_certificate_issued_finds_an_active_certificate_covering_the_domain_across_pages(): void
    {
        Http::fake([
            self::SITE.'/certificates?page=2' => Http::response(['data' => [
                ['id' => 3, 'status' => 'active', 'domain' => 'myshop.com,www.myshop.com'],
            ], 'links' => ['next' => null]]),
            self::SITE.'/certificates' => Http::response(['data' => [
                ['id' => 1, 'status' => 'active', 'domain' => 'other.com'],
                ['id' => 2, 'status' => 'installing', 'domain' => 'myshop.com'],
            ], 'links' => ['next' => 'https://'.self::SITE.'/certificates?page=2']]),
        ]);

        $this->assertTrue($this->host()->certificateIssued('myshop.com'));
    }

    public function test_certificate_issued_is_false_while_still_installing(): void
    {
        Http::fake([
            self::SITE.'/certificates' => Http::response(['data' => [
                ['id' => 2, 'status' => 'installing', 'domain' => 'myshop.com'],
            ], 'links' => ['next' => null]]),
        ]);

        $this->assertFalse($this->host()->certificateIssued('myshop.com'));
    }

    public function test_detach_domain_deletes_the_tenant_and_tolerates_it_being_gone(): void
    {
        Http::fake([
            self::SITE.'/tenants/myshop.com' => Http::response('', 200),
            self::SITE.'/tenants/gone.com' => Http::response(['message' => 'Not found'], 404),
        ]);

        $this->host()->detachDomain('myshop.com');
        $this->host()->detachDomain('gone.com');

        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE' && str_ends_with($request->url(), '/tenants/myshop.com'));
    }

    // ------------------------------------------------------------------
    // Alias mode (Contentio)
    // ------------------------------------------------------------------

    protected function aliasMode(): Ploi
    {
        config(['talivio.infra.ploi.attach_mode' => 'alias']);

        return $this->host();
    }

    /**
     * Ploi's alias endpoint REPLACES the list — sending only the new
     * domain would silently drop every other customer's.
     */
    public function test_alias_mode_attach_sends_the_full_alias_list_plus_the_new_domain(): void
    {
        Http::fake([
            self::SITE.'/aliases' => Http::response(['data' => ['aliases' => ['other.com', 'myshop.com']]]),
            self::SITE => Http::response(['data' => ['id' => 22, 'domain' => 'contentio.talivio.com', 'aliases' => ['other.com']]]),
        ]);

        $this->aliasMode()->attachDomain('MyShop.com');

        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && str_ends_with($request->url(), '/aliases')
            && $request['aliases'] === ['other.com', 'myshop.com']);
    }

    public function test_alias_mode_attach_is_a_no_op_for_an_existing_alias(): void
    {
        Http::fake([
            self::SITE => Http::response(['data' => ['id' => 22, 'aliases' => ['myshop.com']]]),
        ]);

        $this->aliasMode()->attachDomain('myshop.com');

        Http::assertNotSent(fn (Request $request) => $request->method() === 'POST');
    }

    public function test_alias_mode_requests_a_site_certificate_unless_one_already_covers_the_domain(): void
    {
        $certificates = [['id' => 1, 'status' => 'active', 'domain' => 'other.com']];

        Http::fake([
            self::SITE.'/certificates' => function (Request $request) use (&$certificates) {
                if ($request->method() === 'GET') {
                    return Http::response(['data' => $certificates, 'links' => ['next' => null]]);
                }

                // Ploi answers "pending" and finishes the ACME challenge later.
                $certificates[] = ['id' => 9, 'status' => 'pending', 'domain' => $request['certificate']];

                return Http::response(['data' => ['id' => 9]], 201);
            },
        ]);

        $host = $this->aliasMode();
        $host->requestCertificate('myshop.com');

        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && str_ends_with($request->url(), '/certificates')
            && $request['certificate'] === 'myshop.com'
            && $request['type'] === 'letsencrypt');

        // Second request: the pending certificate is found, nothing new is asked for.
        $host->requestCertificate('myshop.com');

        Http::assertSentCount(3); // list, create, list
    }

    public function test_alias_mode_detach_drops_the_certificate_then_the_alias(): void
    {
        Http::fake([
            self::SITE.'/certificates/7' => Http::response('', 200),
            self::SITE.'/certificates' => Http::response(['data' => [
                ['id' => 7, 'status' => 'active', 'domain' => 'myshop.com'],
                ['id' => 8, 'status' => 'active', 'domain' => 'other.com'],
            ], 'links' => ['next' => null]]),
            self::SITE.'/aliases/myshop.com' => Http::response('', 200),
            self::SITE => Http::response(['data' => ['id' => 22, 'aliases' => ['myshop.com', 'other.com']]]),
        ]);

        $this->aliasMode()->detachDomain('myshop.com');

        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE' && str_ends_with($request->url(), '/certificates/7'));
        Http::assertNotSent(fn (Request $request) => $request->method() === 'DELETE' && str_ends_with($request->url(), '/certificates/8'));
        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE' && str_ends_with($request->url(), '/aliases/myshop.com'));
    }

    // ------------------------------------------------------------------
    // Sites (ops)
    // ------------------------------------------------------------------

    public function test_list_sites_follows_pagination(): void
    {
        Http::fake([
            self::SERVER.'/sites?*' => fn (Request $request) => Http::response([
                'data' => [['id' => (int) $request['page'], 'domain' => 'site'.$request['page'].'.test', 'aliases' => []]],
                'meta' => ['last_page' => 2],
            ]),
        ]);

        $sites = $this->host()->listSites();

        $this->assertSame([1, 2], array_column($sites, 'id'));
        Http::assertSent(fn (Request $request) => $request['per_page'] === 50);
    }

    public function test_create_site_posts_the_domain_with_defaults_and_returns_the_new_id(): void
    {
        Http::fake([self::SERVER.'/sites' => Http::response(['data' => ['id' => 333, 'domain' => 'client.example']], 201)]);

        $site = $this->host()->createSite('Client.example', ['project_type' => 'wordpress']);

        $this->assertSame(['id' => 333, 'domain' => 'client.example'], $site);
        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request['root_domain'] === 'client.example'
            && $request['web_directory'] === '/public'
            && $request['project_type'] === 'wordpress');
    }

    public function test_delete_site_tolerates_a_site_that_is_already_gone(): void
    {
        Http::fake([
            self::SERVER.'/sites/333' => Http::response('', 200),
            self::SERVER.'/sites/999' => Http::response(['message' => 'Not found'], 404),
        ]);

        $this->host()->deleteSite(333);
        $this->host()->deleteSite('999');

        Http::assertSentCount(2);
    }

    public function test_site_certificates_are_requested_and_polled_on_the_given_site(): void
    {
        Http::fake([
            self::SERVER.'/sites/333/certificates' => fn (Request $request) => $request->method() === 'GET'
                ? Http::response(['data' => [['id' => 5, 'status' => 'active', 'domain' => 'client.example,www.client.example']], 'links' => ['next' => null]])
                : Http::response(['data' => ['id' => 6]], 201),
        ]);

        $host = $this->host();

        $this->assertTrue($host->siteCertificateIssued(333, 'www.client.example'));
        $this->assertFalse($host->siteCertificateIssued(333, 'other.example'));

        $host->requestSiteCertificate(333, ['other.example'], 'https://talivio.com/hook');

        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request['certificate'] === 'other.example'
            && $request['webhook'] === 'https://talivio.com/hook');
    }
}
