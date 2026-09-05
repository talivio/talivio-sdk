<?php

namespace Talivio\Sdk\Tests\Infra;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Talivio\Sdk\Infra\Clients\Openprovider;
use Talivio\Sdk\Infra\Contracts\Registrar;
use Talivio\Sdk\Tests\TestCase;

class OpenproviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'talivio.infra.openprovider.username' => 'talivio',
            'talivio.infra.openprovider.password' => 'secret',
            'talivio.infra.domains.margin_percent' => 20,
            'talivio.infra.domains.supported_tlds' => ['com', 'eu', 'de'],
        ]);

        // Deliberately no trailing wildcard fake here: Http::fake() calls
        // accumulate rather than replace within a test (each stub is
        // appended, and the FIRST matching stub wins), so a broad '*'
        // registered here would shadow any more specific Http::fake() a
        // test adds later for an endpoint not listed below.
        Http::fake([
            'api.openprovider.eu/v1beta/auth/login' => fn () => $this->loginFails
                ? Http::response(['desc' => 'Authentication failed'], 401)
                : Http::response(['data' => ['token' => 'tok_fake']]),
            // Answers for whatever was asked: free at €10 unless a test
            // marks the domain taken via $this->taken.
            'api.openprovider.eu/v1beta/domains/check' => fn (Request $request) => Http::response(['data' => ['results' => array_map(fn ($d) => [
                'domain' => $d['domain'].'.'.$d['extension'],
                'status' => in_array($d['domain'].'.'.$d['extension'], $this->taken, true) ? 'active' : 'free',
                'price' => ['reseller' => ['price' => $d['extension'] === 'eu' ? 5.00 : 10.00, 'currency' => 'EUR']],
            ], (array) $request['domains'])]]),
            'api.openprovider.eu/v1beta/domains' => Http::response(['data' => ['id' => 12345]]),
        ]);
    }

    /** @var list<string> */
    protected array $taken = [];

    protected bool $loginFails = false;

    protected function registrar(): Openprovider
    {
        return Openprovider::fromConfig() ?? $this->fail('Openprovider should be configured for this test.');
    }

    protected function registrant(): array
    {
        return [
            'name' => 'Jane Owner', 'email' => 'jane@example.com', 'phone' => '+372555',
            'address' => '1 Main St', 'city' => 'Tallinn', 'postal_code' => '10111', 'country' => 'ee',
        ];
    }

    public function test_the_driver_switch_resolves_openprovider(): void
    {
        config(['talivio.infra.registrar' => 'openprovider']);

        $this->assertInstanceOf(Openprovider::class, $this->app->make(Registrar::class));
    }

    public function test_the_quoted_price_includes_talivios_margin(): void
    {
        $result = $this->registrar()->checkAvailability('myshop.com');

        // €10.00 reseller price + 20% margin = €12.00.
        $this->assertSame(1200, $result['price']);
        $this->assertSame('EUR', $result['currency']);
        $this->assertTrue($result['available']);
        $this->assertFalse($result['premium']);
    }

    public function test_a_zero_margin_passes_the_reseller_price_through(): void
    {
        config(['talivio.infra.domains.margin_percent' => 0]);

        $this->assertSame(1000, $this->registrar()->checkAvailability('myshop.com')['price']);
    }

    public function test_check_many_sends_every_domain_in_one_request_and_keys_the_answers_by_domain(): void
    {
        $this->taken = ['myshop.eu'];

        $results = $this->registrar()->checkMany(['myshop.com', 'myshop.eu', 'myshop.xyz']);

        $this->assertSame(['myshop.com', 'myshop.eu'], array_keys($results));
        $this->assertTrue($results['myshop.com']['available']);
        $this->assertFalse($results['myshop.eu']['available']);
        $this->assertSame(600, $results['myshop.eu']['price']);

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/domains/check')
            && $request['domains'] === [['domain' => 'myshop', 'extension' => 'com'], ['domain' => 'myshop', 'extension' => 'eu']]);
    }

    public function test_an_unsupported_tld_is_rejected_before_any_api_call(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("doesn't sell .xyz");

        $this->registrar()->checkAvailability('myshop.xyz');
    }

    public function test_registration_of_an_unsupported_tld_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);

        $this->registrar()->register('myshop.xyz', $this->registrant(), ['ns1.example-dns.net', 'ns2.example-dns.net']);
    }

    public function test_registration_enables_whois_privacy(): void
    {
        $this->registrar()->register('myshop.com', $this->registrant(), ['ns1.example-dns.net', 'ns2.example-dns.net']);

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/domains')
            && $request['is_private_whois_enabled'] === true
            && $request['owner']['name']['full_name'] === 'Jane Owner'
            && $request['owner']['address']['country'] === 'EE');
    }

    public function test_an_empty_supported_tld_list_allows_everything(): void
    {
        config(['talivio.infra.domains.supported_tlds' => []]);

        $this->assertTrue($this->registrar()->checkAvailability('myshop.xyz')['available']);
    }

    public function test_transfer_in_submits_the_auth_code_and_enables_whois_privacy(): void
    {
        Http::fake([
            'api.openprovider.eu/v1beta/auth/login' => Http::response(['data' => ['token' => 'tok_fake']]),
            'api.openprovider.eu/v1beta/domains/transfer' => Http::response(['data' => ['id' => 999]]),
        ]);

        $id = $this->registrar()->transferIn('myshop.com', 'AUTH123', $this->registrant());

        $this->assertSame('999', $id);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/domains/transfer')
            && $request['auth_code'] === 'AUTH123'
            && $request['is_private_whois_enabled'] === true);
    }

    public function test_transfer_in_of_an_unsupported_tld_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);

        $this->registrar()->transferIn('myshop.xyz', 'AUTH123', $this->registrant());
    }

    /**
     * Three registrar ids rather than three Http::fake() calls in one test:
     * Http::fake() stubs accumulate (first match wins) within a single
     * test, so a second call for the same URL never overrides the first —
     * see the comment on setUp()'s deliberately absent wildcard fake.
     */
    public function test_transfer_status_maps_an_active_domain_to_completed(): void
    {
        Http::fake([
            'api.openprovider.eu/v1beta/auth/login' => Http::response(['data' => ['token' => 'tok_fake']]),
            'api.openprovider.eu/v1beta/domains/1' => Http::response(['data' => ['status' => 'ACT']]),
        ]);

        $this->assertSame('completed', $this->registrar()->transferStatus('1'));
    }

    public function test_transfer_status_maps_a_failed_domain_to_failed(): void
    {
        Http::fake([
            'api.openprovider.eu/v1beta/auth/login' => Http::response(['data' => ['token' => 'tok_fake']]),
            'api.openprovider.eu/v1beta/domains/2' => Http::response(['data' => ['status' => 'FAI']]),
        ]);

        $this->assertSame('failed', $this->registrar()->transferStatus('2'));
    }

    public function test_transfer_status_maps_anything_else_to_pending(): void
    {
        Http::fake([
            'api.openprovider.eu/v1beta/auth/login' => Http::response(['data' => ['token' => 'tok_fake']]),
            'api.openprovider.eu/v1beta/domains/3' => Http::response(['data' => ['status' => 'PEN']]),
        ]);

        $this->assertSame('pending', $this->registrar()->transferStatus('3'));
    }

    public function test_get_auth_code_returns_the_registrars_code(): void
    {
        Http::fake([
            'api.openprovider.eu/v1beta/auth/login' => Http::response(['data' => ['token' => 'tok_fake']]),
            'api.openprovider.eu/v1beta/domains/1/auth-code' => Http::response(['data' => ['auth_code' => 'SECRET123']]),
        ]);

        $this->assertSame('SECRET123', $this->registrar()->getAuthCode('myshop.com', '1'));
    }

    public function test_get_auth_code_throws_when_the_registrar_returns_none(): void
    {
        Http::fake([
            'api.openprovider.eu/v1beta/auth/login' => Http::response(['data' => ['token' => 'tok_fake']]),
            'api.openprovider.eu/v1beta/domains/1/auth-code' => Http::response(['data' => []]),
        ]);

        $this->expectException(RuntimeException::class);

        $this->registrar()->getAuthCode('myshop.com', '1');
    }

    public function test_registration_delegates_to_the_given_nameservers(): void
    {
        $this->registrar()->register('myshop.com', $this->registrant(), ['ns1.example-dns.net', 'ns2.example-dns.net']);

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/domains')
            && $request['name_servers'] === [['name' => 'ns1.example-dns.net'], ['name' => 'ns2.example-dns.net']]);
    }

    public function test_registration_without_nameservers_leaves_the_registrars_defaults(): void
    {
        $this->registrar()->register('myshop.com', $this->registrant(), []);

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/domains') && ! isset($request['name_servers']));
    }

    public function test_configure_nameservers_updates_the_domains_nameservers(): void
    {
        Http::fake([
            'api.openprovider.eu/v1beta/auth/login' => Http::response(['data' => ['token' => 'tok_fake']]),
            'api.openprovider.eu/v1beta/domains/1' => Http::response(['data' => []]),
        ]);

        $this->registrar()->configureNameservers('myshop.com', '1', ['ns1.example-dns.net']);

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/domains/1')
            && $request->method() === 'PUT'
            && $request['name_servers'] === [['name' => 'ns1.example-dns.net']]);
    }

    public function test_set_transfer_lock_sends_the_locked_flag(): void
    {
        Http::fake([
            'api.openprovider.eu/v1beta/auth/login' => Http::response(['data' => ['token' => 'tok_fake']]),
            'api.openprovider.eu/v1beta/domains/1' => Http::response(['data' => []]),
        ]);

        $this->registrar()->setTransferLock('myshop.com', '1', false);

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/domains/1')
            && $request->method() === 'PUT'
            && $request['is_locked'] === false);
    }

    public function test_a_failed_login_surfaces_before_the_real_call(): void
    {
        $this->loginFails = true;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('authentication failed');

        $this->registrar()->transferStatus('1');
    }
}
