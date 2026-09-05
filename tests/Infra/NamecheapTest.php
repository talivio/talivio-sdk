<?php

namespace Talivio\Sdk\Tests\Infra;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Talivio\Sdk\Infra\Clients\Namecheap;
use Talivio\Sdk\Infra\Contracts\Registrar;
use Talivio\Sdk\Infra\Exceptions\AuthCodeUnavailableException;
use Talivio\Sdk\Infra\Exceptions\NotConfiguredException;
use Talivio\Sdk\Infra\Support\UnconfiguredRegistrar;
use Talivio\Sdk\Tests\TestCase;

/**
 * Namecheap is one endpoint for every command, so unlike the Openprovider
 * test (one Http::fake stub per URL) each test fakes the sandbox URL with a
 * callback that dispatches on the Command form field.
 */
class NamecheapTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        config([
            'talivio.infra.namecheap.api_key' => 'key_fake',
            'talivio.infra.namecheap.username' => 'talivio',
            'talivio.infra.namecheap.api_user' => 'talivio',
            'talivio.infra.namecheap.client_ip' => '203.0.113.10',
            'talivio.infra.namecheap.sandbox' => true,
            'talivio.infra.domains.margin_percent' => 20,
            'talivio.infra.domains.supported_tlds' => ['com', 'eu', 'de'],
        ]);
    }

    protected function registrar(): Namecheap
    {
        return Namecheap::fromConfig() ?? $this->fail('Namecheap should be configured for this test.');
    }

    protected function nameservers(): array
    {
        return ['ada.ns.cloudflare.com', 'bob.ns.cloudflare.com'];
    }

    protected function registrant(): array
    {
        return [
            'name' => 'Jane Owner', 'email' => 'jane@example.com', 'phone' => '+372 5551234',
            'address' => '1 Main St', 'city' => 'Tallinn', 'postal_code' => '10111', 'country' => 'ee',
        ];
    }

    /**
     * @param  array<string, string|\Closure(Request): string>  $byCommand  XML body (or a closure producing one) per Command
     */
    protected function fakeApi(array $byCommand): void
    {
        Http::fake([
            'api.sandbox.namecheap.com/*' => function (Request $request) use ($byCommand) {
                $command = $request['Command'];
                $body = $byCommand[$command] ?? null;

                if ($body === null) {
                    return Http::response($this->envelope('ERROR', '', '<Errors><Error Number="9999">Unexpected command '.$command.'</Error></Errors>'), 200);
                }

                return Http::response($body instanceof \Closure ? $body($request) : $body, 200);
            },
        ]);
    }

    protected function envelope(string $status, string $commandResponse, string $errors = '<Errors />'): string
    {
        return '<?xml version="1.0" encoding="utf-8"?>'
            .'<ApiResponse Status="'.$status.'" xmlns="http://api.namecheap.com/xml.response">'
            .$errors.'<Warnings />'
            .'<CommandResponse Type="x">'.$commandResponse.'</CommandResponse>'
            .'</ApiResponse>';
    }

    protected function checkResult(string $domain, bool $available, bool $premium = false, string $errorNo = '0', string $description = ''): string
    {
        return '<DomainCheckResult Domain="'.$domain.'" Available="'.($available ? 'true' : 'false').'" ErrorNo="'.$errorNo.'" Description="'.$description.'" IsPremiumName="'.($premium ? 'true' : 'false').'" PremiumRegistrationPrice="0" IcannFee="0" />';
    }

    protected function checkXml(string $domain, bool $available, bool $premium = false): string
    {
        return $this->envelope('OK', $this->checkResult($domain, $available, $premium));
    }

    /**
     * Both categories getPricing actually returns in one call — registrationPrice()
     * quotes max(register, renew) + AdditionalCost, see its docblock. Defaults
     * keep register and renew equal (no promo) so existing price assertions
     * read as a plain "$10 + margin"; tests of the promo/additional-cost
     * behavior override the relevant argument.
     */
    protected function pricingXml(string $tld = 'com', string $registerYourPrice = '10.00', string $renewYourPrice = '10.00', string $additionalCost = '0.00'): string
    {
        $product = fn (string $yourPrice) => '<Product Name="'.$tld.'">'
            .'<Price Duration="1" DurationType="YEAR" Price="'.$yourPrice.'" AdditionalCost="'.$additionalCost.'" RegularPrice="'.$yourPrice.'" YourPrice="'.$yourPrice.'" Currency="USD" />'
            .'<Price Duration="2" DurationType="YEAR" Price="20.00" AdditionalCost="'.$additionalCost.'" RegularPrice="20.00" YourPrice="20.00" Currency="USD" />'
            .'</Product>';

        return $this->envelope('OK', '<UserGetPricingResult><ProductType Name="domains">'
            .'<ProductCategory Name="register">'.$product($registerYourPrice).'</ProductCategory>'
            .'<ProductCategory Name="renew">'.$product($renewYourPrice).'</ProductCategory>'
            .'</ProductType></UserGetPricingResult>');
    }

    public function test_the_container_resolves_namecheap_as_the_default_registrar(): void
    {
        $this->assertInstanceOf(Namecheap::class, $this->app->make(Registrar::class));
    }

    /**
     * The contract still resolves without credentials (a controller that
     * injects it must build); the first CALL fails naming the env keys.
     * Asking for the concrete class is different — that fails at once.
     */
    public function test_an_unconfigured_registrar_resolves_but_fails_on_use_naming_the_env_keys(): void
    {
        config(['talivio.infra.namecheap.api_key' => null]);

        $this->assertNull(Namecheap::fromConfig());

        $registrar = $this->app->make(Registrar::class);

        $this->assertInstanceOf(UnconfiguredRegistrar::class, $registrar);

        try {
            $this->app->make(Namecheap::class);
            $this->fail('The concrete client should not be built without credentials.');
        } catch (NotConfiguredException $e) {
            $this->assertStringContainsString('NAMECHEAP_API_KEY', $e->getMessage());
        }

        $this->expectException(NotConfiguredException::class);
        $this->expectExceptionMessage('NAMECHEAP_API_KEY');

        $registrar->checkAvailability('myshop.com');
    }

    public function test_the_quoted_price_is_the_accounts_own_price_plus_talivios_margin(): void
    {
        $this->fakeApi([
            'namecheap.domains.check' => $this->checkXml('myshop.com', true),
            'namecheap.users.getPricing' => $this->pricingXml(),
        ]);

        $result = $this->registrar()->checkAvailability('myshop.com');

        // $10.00 quote (register == renew here) + 20% margin = $12.00.
        $this->assertTrue($result['available']);
        $this->assertFalse($result['premium']);
        $this->assertSame(1200, $result['price']);
        $this->assertSame('USD', $result['currency']);
    }

    public function test_every_call_carries_the_account_globals_and_posts_as_a_form(): void
    {
        $this->fakeApi([
            'namecheap.domains.check' => $this->checkXml('myshop.com', true),
            'namecheap.users.getPricing' => $this->pricingXml(),
        ]);

        $this->registrar()->checkAvailability('myshop.com');

        Http::assertSent(fn (Request $request) => $request['Command'] === 'namecheap.domains.check'
            && $request->method() === 'POST'
            && $request['ApiUser'] === 'talivio'
            && $request['ApiKey'] === 'key_fake'
            && $request['UserName'] === 'talivio'
            && $request['ClientIp'] === '203.0.113.10'
            && $request['DomainList'] === 'myshop.com');
    }

    public function test_the_api_user_falls_back_to_the_username(): void
    {
        config(['talivio.infra.namecheap.api_user' => null]);

        $this->fakeApi([
            'namecheap.domains.check' => $this->checkXml('myshop.com', true),
            'namecheap.users.getPricing' => $this->pricingXml(),
        ]);

        $this->registrar()->checkAvailability('myshop.com');

        Http::assertSent(fn (Request $request) => $request['ApiUser'] === 'talivio' && $request['UserName'] === 'talivio');
    }

    public function test_sandbox_off_targets_the_production_endpoint(): void
    {
        config(['talivio.infra.namecheap.sandbox' => false]);

        Http::fake([
            'api.namecheap.com/*' => Http::response($this->checkXml('myshop.com', false)),
            'api.sandbox.namecheap.com/*' => Http::response('', 500),
        ]);
        Cache::put('namecheap:price:v2:com', [1000, 'USD'], 60);

        $this->assertFalse($this->registrar()->checkAvailability('myshop.com')['available']);

        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://api.namecheap.com/'));
    }

    public function test_a_zero_margin_passes_the_reseller_price_through(): void
    {
        config(['talivio.infra.domains.margin_percent' => 0]);

        $this->fakeApi([
            'namecheap.domains.check' => $this->checkXml('myshop.com', true),
            'namecheap.users.getPricing' => $this->pricingXml(),
        ]);

        $this->assertSame(1000, $this->registrar()->checkAvailability('myshop.com')['price']);
    }

    public function test_the_quote_is_the_higher_of_register_and_renew_plus_the_additional_cost(): void
    {
        // A first-year promo (register $0.98) must not undercharge every
        // renewal after it — see registrationPrice()'s docblock. Renew
        // $15.00 wins over register $0.98; +$0.20 ICANN-style additional
        // cost on top; the margin applies to that total.
        $this->fakeApi([
            'namecheap.domains.check' => $this->checkXml('myshop.com', true),
            'namecheap.users.getPricing' => $this->pricingXml(registerYourPrice: '0.98', renewYourPrice: '15.00', additionalCost: '0.20'),
        ]);

        // ($15.00 + $0.20) * 1.20 = $18.24.
        $this->assertSame(1824, $this->registrar()->checkAvailability('myshop.com')['price']);
    }

    public function test_the_tld_price_is_cached_across_searches(): void
    {
        $this->fakeApi([
            'namecheap.domains.check' => fn (Request $request) => $this->checkXml($request['DomainList'], true),
            'namecheap.users.getPricing' => $this->pricingXml(),
        ]);

        $this->registrar()->checkAvailability('myshop.com');
        $this->registrar()->checkAvailability('otherstore.com');

        Http::assertSentCount(3); // two checks, one pricing lookup
    }

    public function test_a_taken_domain_is_reported_unavailable_but_still_priced(): void
    {
        $this->fakeApi([
            'namecheap.domains.check' => $this->checkXml('myshop.com', false),
            'namecheap.users.getPricing' => $this->pricingXml(),
        ]);

        $result = $this->registrar()->checkAvailability('myshop.com');

        // Transfers reuse this quote.
        $this->assertFalse($result['available']);
        $this->assertSame(1200, $result['price']);
    }

    public function test_a_premium_domain_is_not_sold(): void
    {
        $this->fakeApi([
            'namecheap.domains.check' => $this->checkXml('myshop.com', true, premium: true),
            'namecheap.users.getPricing' => $this->pricingXml(),
        ]);

        $result = $this->registrar()->checkAvailability('myshop.com');

        $this->assertFalse($result['available']);
        $this->assertTrue($result['premium']);
    }

    public function test_an_unsupported_tld_is_rejected_before_any_api_call(): void
    {
        Http::fake();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("doesn't sell .xyz");

        try {
            $this->registrar()->checkAvailability('myshop.xyz');
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_an_empty_supported_tld_list_allows_everything(): void
    {
        config(['talivio.infra.domains.supported_tlds' => []]);

        $this->fakeApi([
            'namecheap.domains.check' => $this->checkXml('myshop.xyz', true),
            'namecheap.users.getPricing' => $this->pricingXml('xyz'),
        ]);

        $this->assertTrue($this->registrar()->checkAvailability('myshop.xyz')['available']);
    }

    public function test_namecheaps_own_error_text_surfaces_in_the_exception(): void
    {
        $this->fakeApi([
            'namecheap.domains.check' => $this->envelope('ERROR', '', '<Errors><Error Number="1011102">API Key is invalid or API access has not been enabled</Error></Errors>'),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('API Key is invalid or API access has not been enabled (#1011102)');

        $this->registrar()->checkAvailability('myshop.com');
    }

    public function test_check_many_batches_the_domains_into_one_call_and_prices_each_ending(): void
    {
        config(['talivio.infra.domains.supported_tlds' => []]);

        $this->fakeApi([
            'namecheap.domains.check' => fn (Request $request) => $this->envelope('OK',
                $this->checkResult('myshop.com', true).$this->checkResult('myshop.net', false).$this->checkResult('myshop.io', true, premium: true)),
            'namecheap.users.getPricing' => fn (Request $request) => $this->pricingXml($request['ProductName'], $request['ProductName'] === 'io' ? '30.00' : '10.00'),
        ]);

        $results = $this->registrar()->checkMany(['MyShop.com', 'myshop.net', 'myshop.io', 'myshop.com']);

        $this->assertSame(['myshop.com', 'myshop.net', 'myshop.io'], array_keys($results));
        $this->assertTrue($results['myshop.com']['available']);
        $this->assertSame(1200, $results['myshop.com']['price']);
        $this->assertFalse($results['myshop.net']['available']);
        $this->assertFalse($results['myshop.io']['available']);
        $this->assertTrue($results['myshop.io']['premium']);
        $this->assertSame(3600, $results['myshop.io']['price']);

        Http::assertSent(fn (Request $request) => $request['Command'] === 'namecheap.domains.check'
            && $request['DomainList'] === 'myshop.com,myshop.net,myshop.io');
        Http::assertSentCount(4); // one batch check + three pricing lookups
    }

    /**
     * One ending the account can't check fails the WHOLE batch at
     * Namecheap — the search must degrade to "skip that ending", not
     * "the search box stops working".
     */
    public function test_check_many_falls_back_to_one_call_per_domain_when_the_batch_is_rejected(): void
    {
        config(['talivio.infra.domains.supported_tlds' => []]);

        $this->fakeApi([
            'namecheap.domains.check' => function (Request $request) {
                $list = $request['DomainList'];

                if (str_contains($list, ',') || str_ends_with($list, '.ee')) {
                    return $this->envelope('ERROR', '', '<Errors><Error Number="2030280">TLD for \'myshop.ee\' is not found</Error></Errors>');
                }

                return $this->checkXml($list, true);
            },
            'namecheap.users.getPricing' => fn (Request $request) => $this->pricingXml($request['ProductName']),
        ]);

        $results = $this->registrar()->checkMany(['myshop.com', 'myshop.ee']);

        $this->assertSame(['myshop.com'], array_keys($results));
        $this->assertTrue($results['myshop.com']['available']);
    }

    public function test_check_many_skips_unsupported_endings_and_malformed_names_without_calling_out(): void
    {
        Http::fake();

        $this->assertSame([], $this->registrar()->checkMany(['myshop.xyz', 'not-a-domain']));

        Http::assertNothingSent();
    }

    public function test_registration_delegates_to_the_given_nameservers_with_whois_privacy_and_all_four_contacts(): void
    {
        $this->fakeApi([
            'namecheap.domains.create' => $this->envelope('OK', '<DomainCreateResult Domain="myshop.com" Registered="true" ChargedAmount="10.87" DomainID="1234567" OrderID="987654" TransactionID="112233" WhoisguardEnable="true" NonRealTimeDomain="false" />'),
        ]);

        $id = $this->registrar()->register('myshop.com', $this->registrant(), $this->nameservers());

        $this->assertSame('1234567', $id);

        Http::assertSent(function (Request $request) {
            if ($request['Command'] !== 'namecheap.domains.create') {
                return false;
            }

            foreach (['Registrant', 'Tech', 'Admin', 'AuxBilling'] as $role) {
                if ($request[$role.'FirstName'] !== 'Jane'
                    || $request[$role.'LastName'] !== 'Owner'
                    || $request[$role.'Country'] !== 'EE'
                    || $request[$role.'StateProvince'] !== 'Tallinn'
                    || $request[$role.'Phone'] !== '+372.5551234'
                    || $request[$role.'EmailAddress'] !== 'jane@example.com') {
                    return false;
                }
            }

            return $request['DomainName'] === 'myshop.com'
                && $request['Years'] === '1'
                && $request['Nameservers'] === 'ada.ns.cloudflare.com,bob.ns.cloudflare.com'
                && $request['AddFreeWhoisguard'] === 'yes'
                && $request['WGEnabled'] === 'yes'
                && ! isset($request['IsPremiumDomain']);
        });
    }

    /**
     * Cloudflare refuses a zone for a not-yet-registered name, so a
     * product registering BEFORE creating the zone has no nameservers to
     * give — the domain stays on Namecheap's defaults until
     * configureNameservers() runs.
     */
    public function test_registration_without_nameservers_leaves_the_registrars_defaults(): void
    {
        $this->fakeApi([
            'namecheap.domains.create' => $this->envelope('OK', '<DomainCreateResult Domain="myshop.com" Registered="true" DomainID="1" />'),
        ]);

        $this->registrar()->register('myshop.com', $this->registrant(), []);

        Http::assertSent(fn (Request $request) => $request['Command'] === 'namecheap.domains.create' && ! isset($request['Nameservers']));
    }

    public function test_a_single_word_registrant_name_still_yields_a_last_name(): void
    {
        $this->fakeApi([
            'namecheap.domains.create' => $this->envelope('OK', '<DomainCreateResult Domain="myshop.com" Registered="true" DomainID="1" />'),
        ]);

        $this->registrar()->register('myshop.com', ['name' => 'Madonna'] + $this->registrant(), $this->nameservers());

        Http::assertSent(fn (Request $request) => $request['RegistrantFirstName'] === 'Madonna'
            && $request['RegistrantLastName'] === 'Madonna');
    }

    public function test_an_explicit_state_is_preferred_over_the_city_fallback(): void
    {
        $this->fakeApi([
            'namecheap.domains.create' => $this->envelope('OK', '<DomainCreateResult Domain="myshop.com" Registered="true" DomainID="1" />'),
        ]);

        $this->registrar()->register('myshop.com', ['state' => 'Harju'] + $this->registrant(), $this->nameservers());

        Http::assertSent(fn (Request $request) => $request['RegistrantStateProvince'] === 'Harju');
    }

    public function test_registration_that_namecheap_does_not_confirm_throws(): void
    {
        $this->fakeApi([
            'namecheap.domains.create' => $this->envelope('OK', '<DomainCreateResult Domain="myshop.com" Registered="false" DomainID="0" />'),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('did not confirm the registration');

        $this->registrar()->register('myshop.com', $this->registrant(), $this->nameservers());
    }

    public function test_registration_of_an_unsupported_tld_is_rejected(): void
    {
        Http::fake();

        $this->expectException(RuntimeException::class);

        $this->registrar()->register('myshop.xyz', $this->registrant(), $this->nameservers());
    }

    public function test_renew_is_keyed_by_domain_name_not_registrar_id(): void
    {
        $this->fakeApi([
            'namecheap.domains.renew' => $this->envelope('OK', '<DomainRenewResult DomainName="myshop.com" DomainID="1234567" Renew="true" OrderID="1" TransactionID="1" ChargedAmount="9.56" />'),
        ]);

        $this->registrar()->renew('myshop.com', '1234567', 2);

        Http::assertSent(fn (Request $request) => $request['Command'] === 'namecheap.domains.renew'
            && $request['DomainName'] === 'myshop.com'
            && $request['Years'] === '2'
            && ! isset($request['DomainID']));
    }

    public function test_renew_that_namecheap_does_not_confirm_throws(): void
    {
        $this->fakeApi([
            'namecheap.domains.renew' => $this->envelope('OK', '<DomainRenewResult DomainName="myshop.com" Renew="false" />'),
        ]);

        $this->expectException(RuntimeException::class);

        $this->registrar()->renew('myshop.com', '1234567');
    }

    public function test_transfer_in_submits_the_epp_code_with_whois_privacy_and_returns_the_transfer_id(): void
    {
        $this->fakeApi([
            'namecheap.domains.transfer.create' => $this->envelope('OK', '<DomainTransferCreateResult DomainName="myshop.com" Transfer="true" TransferID="123456" StatusID="11" OrderID="1" TransactionID="1" ChargedAmount="10.87" />'),
        ]);

        $id = $this->registrar()->transferIn('myshop.com', 'AUTH123', $this->registrant());

        $this->assertSame('123456', $id);
        Http::assertSent(fn (Request $request) => $request['Command'] === 'namecheap.domains.transfer.create'
            && $request['DomainName'] === 'myshop.com'
            && $request['EPPCode'] === 'AUTH123'
            && $request['Years'] === '1'
            && $request['AddFreeWhoisguard'] === 'yes'
            && $request['WGenable'] === 'yes');
    }

    public function test_transfer_in_of_an_unsupported_tld_is_rejected(): void
    {
        Http::fake();

        $this->expectException(RuntimeException::class);

        $this->registrar()->transferIn('myshop.xyz', 'AUTH123', $this->registrant());
    }

    #[DataProvider('transferStatuses')]
    public function test_transfer_status_is_classified_from_namecheaps_free_text(string $namecheapStatus, string $expected): void
    {
        $this->fakeApi([
            'namecheap.domains.transfer.getStatus' => $this->envelope('OK', '<DomainTransferGetStatusResult TransferID="123456" StatusID="5" Status="'.$namecheapStatus.'" />'),
        ]);

        $this->assertSame($expected, $this->registrar()->transferStatus('123456'));

        Http::assertSent(fn (Request $request) => $request['TransferID'] === '123456');
    }

    public static function transferStatuses(): array
    {
        return [
            'completed' => ['Transfer completed', 'completed'],
            'cancelled by registry' => ['Cancelled - Invalid EPP code', 'failed'],
            'rejected' => ['Transfer rejected by the losing registrar', 'failed'],
            'awaiting epp' => ['Transfer in progress, awaiting EPP code', 'pending'],
            'queued' => ['Queued for submission', 'pending'],
        ];
    }

    public function test_configure_nameservers_sets_custom_dns_on_the_split_domain(): void
    {
        $this->fakeApi([
            'namecheap.domains.dns.setCustom' => $this->envelope('OK', '<DomainDNSSetCustomResult Domain="myshop.com" Updated="true" />'),
        ]);

        $this->registrar()->configureNameservers('myshop.com', '123456', $this->nameservers());

        Http::assertSent(fn (Request $request) => $request['Command'] === 'namecheap.domains.dns.setCustom'
            && $request['SLD'] === 'myshop'
            && $request['TLD'] === 'com'
            && $request['Nameservers'] === 'ada.ns.cloudflare.com,bob.ns.cloudflare.com');
    }

    public function test_configure_nameservers_with_an_empty_list_is_refused_before_any_api_call(): void
    {
        Http::fake();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No nameservers');

        try {
            $this->registrar()->configureNameservers('myshop.com', '123456', ['', ' ']);
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_configure_nameservers_that_namecheap_does_not_confirm_throws(): void
    {
        $this->fakeApi([
            'namecheap.domains.dns.setCustom' => $this->envelope('OK', '<DomainDNSSetCustomResult Domain="myshop.com" Updated="false" />'),
        ]);

        $this->expectException(RuntimeException::class);

        $this->registrar()->configureNameservers('myshop.com', '123456', $this->nameservers());
    }

    public function test_the_auth_code_is_not_available_over_the_api(): void
    {
        Http::fake();

        $this->expectException(AuthCodeUnavailableException::class);

        try {
            $this->registrar()->getAuthCode('myshop.com', '1234567');
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_set_transfer_lock_maps_to_lock_and_unlock_actions(): void
    {
        $this->fakeApi([
            'namecheap.domains.setRegistrarLock' => $this->envelope('OK', '<DomainSetRegistrarLockResult Domain="myshop.com" IsSuccess="true" />'),
        ]);

        $this->registrar()->setTransferLock('myshop.com', '1234567', false);
        $this->registrar()->setTransferLock('myshop.com', '1234567', true);

        Http::assertSent(fn (Request $request) => $request['LockAction'] === 'UNLOCK' && $request['DomainName'] === 'myshop.com');
        Http::assertSent(fn (Request $request) => $request['LockAction'] === 'LOCK' && $request['DomainName'] === 'myshop.com');
    }

    public function test_an_unreadable_response_throws(): void
    {
        Http::fake(['api.sandbox.namecheap.com/*' => Http::response('<html>Just a moment...</html>', 200)]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unreadable response');

        $this->registrar()->setTransferLock('myshop.com', '1', true);
    }
}
