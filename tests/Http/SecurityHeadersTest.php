<?php

namespace Talivio\Sdk\Tests\Http;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Talivio\Sdk\Http\Middleware\SecurityHeaders;
use Talivio\Sdk\Http\Security\Csp;
use Talivio\Sdk\Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Üründe olduğu gibi EN DIŞTA: yanıtı, web grubunun tamamı işini
        // bitirdikten sonra görür.
        Route::middleware([SecurityHeaders::class, 'web'])->group(function () {
            Route::get('/probe', fn () => 'ok');
            Route::get('/inline', fn () => Blade::render('<script @talivioNonce>1</script>'));
            Route::get('/preset', fn () => response('ok')->header('Referrer-Policy', 'no-referrer'));
        });
    }

    public function test_it_sends_the_headers_a_hardened_site_is_expected_to_send(): void
    {
        $res = $this->get('https://localhost/probe');

        $res->assertOk();
        $res->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains; preload');
        $res->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $res->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('geolocation=()', $res->headers->get('Permissions-Policy'));
        $this->assertStringContainsString("frame-ancestors 'self'", $res->headers->get('Content-Security-Policy'));
    }

    /**
     * HSTS düz HTTP üzerinde gönderilmemeli: yerel geliştirmede tarayıcı
     * siteyi kalıcı olarak https'e kilitler ve makine http://localhost'a bir
     * daha bağlanamaz.
     */
    public function test_it_does_not_send_hsts_over_plain_http(): void
    {
        $this->get('http://localhost/probe')->assertHeaderMissing('Strict-Transport-Security');
    }

    /**
     * Ürünlerin nginx'i bu başlıkların bir kısmını zaten yazıyor; ikinci kez
     * yazmak yinelenmiş başlık üretir.
     */
    public function test_it_never_overwrites_a_header_the_response_already_carries(): void
    {
        $this->get('https://localhost/preset')->assertHeader('Referrer-Policy', 'no-referrer');
    }

    /**
     * ASIL SINAV: politika TCSR'ın kendi http_headers modülünün iki
     * denetiminden geçmeli. Regex'ler oradan birebir kopyalandı
     * (TCSR app/Services/Scan/Modules/HttpHeadersModule.php) — üretilen
     * politika değişip de bulgu üretmeye başlarsa burada yakalanır.
     */
    public function test_the_policy_passes_the_scanner_that_grades_our_own_sites(): void
    {
        $csp = strtolower($this->get('https://localhost/probe')->headers->get('Content-Security-Policy'));

        $this->assertDoesNotMatchRegularExpression('/script-src[^;]*(\*|https:)(\s|;|$)/', $csp,
            'script-src joker ya da çıplak "https:" içeriyor — TCSR bunu medium sayıyor.');

        $scriptDirective = preg_match('/(?:^|;)\s*script-src([^;]*)/', $csp, $m) ? $m[1] : '';
        $this->assertStringContainsString("'nonce-", $scriptDirective);
        $this->assertStringNotContainsString("'unsafe-inline'", $scriptDirective);
        $this->assertStringNotContainsString("'unsafe-eval'", $scriptDirective);
    }

    public function test_the_nonce_in_the_header_is_the_one_blade_prints(): void
    {
        $res = $this->get('https://localhost/inline');

        preg_match('/nonce="([^"]+)"/', $res->getContent(), $inBody);
        $this->assertNotEmpty($inBody, 'The @talivioNonce directive printed nothing.');
        $this->assertStringContainsString("'nonce-".$inBody[1]."'", $res->headers->get('Content-Security-Policy'));
    }

    /**
     * Nonce isteğe özgü olmazsa "bu sayfaya ait" iddiasını taşıyamaz.
     *
     * Testte istekler tek bir container'ı paylaşıyor (gerçekte php-fpm'de her
     * istek yeni bir süreç, Octane'da ise scoped bağlamalar istek başında
     * temizleniyor). Bu yüzden burada o temizliği elle yapıyoruz — sınanan şey
     * tam olarak nonce'un `scoped`'a bağlı olması.
     */
    public function test_every_request_gets_its_own_nonce(): void
    {
        $first = $this->get('https://localhost/probe')->headers->get('Content-Security-Policy');

        $this->app->forgetScopedInstances();

        $second = $this->get('https://localhost/probe')->headers->get('Content-Security-Policy');

        $this->assertNotSame($first, $second);
    }

    public function test_a_product_can_add_sources_to_a_directive(): void
    {
        config(['talivio.security.csp.sources' => ['script-src' => ['https://js.stripe.com']]]);

        $csp = $this->get('https://localhost/probe')->headers->get('Content-Security-Policy');

        $this->assertStringContainsString('https://js.stripe.com', $csp);
        $this->assertStringContainsString("'self'", $csp);
    }

    public function test_a_product_can_replace_a_directive_outright(): void
    {
        config(['talivio.security.csp.directives' => [
            'frame-ancestors' => ['https://admin.shopify.com', 'https://*.myshopify.com'],
        ]]);

        $csp = $this->get('https://localhost/probe')->headers->get('Content-Security-Policy');

        $this->assertStringContainsString('frame-ancestors https://admin.shopify.com https://*.myshopify.com', $csp);
        $this->assertStringNotContainsString("frame-ancestors 'self'", $csp);
    }

    public function test_report_only_mode_sends_the_policy_without_enforcing_it(): void
    {
        config(['talivio.security.csp.report_only' => true]);

        $res = $this->get('https://localhost/probe');

        $res->assertHeaderMissing('Content-Security-Policy');
        $this->assertStringContainsString("default-src 'self'", $res->headers->get('Content-Security-Policy-Report-Only'));
    }

    public function test_strict_dynamic_is_opt_in(): void
    {
        $this->assertStringNotContainsString("'strict-dynamic'",
            $this->get('https://localhost/probe')->headers->get('Content-Security-Policy'));

        config(['talivio.security.csp.strict_dynamic' => true]);

        $this->assertStringContainsString("'strict-dynamic'",
            $this->get('https://localhost/probe')->headers->get('Content-Security-Policy'));
    }

    public function test_the_csp_can_be_switched_off_entirely(): void
    {
        config(['talivio.security.csp.enabled' => false]);

        $res = $this->get('https://localhost/probe');

        $res->assertHeaderMissing('Content-Security-Policy');
        $res->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    /**
     * Çerezi HttpOnly yapmak, jetonu ÇEREZDEN okuyan kurulumlarda (axios'un
     * varsayılanı) bütün POST'ları 419'a düşürür — o yüzden varsayılan kapalı.
     */
    public function test_the_xsrf_cookie_is_only_hardened_when_the_product_asks(): void
    {
        $this->assertFalse($this->xsrfCookieIsHttpOnly());

        config(['talivio.security.harden_xsrf_cookie' => true]);

        $this->assertTrue($this->xsrfCookieIsHttpOnly());
    }

    public function test_the_nonce_is_stable_within_one_request(): void
    {
        $csp = $this->app->make(Csp::class);

        $this->assertSame($csp->nonce(), $csp->nonce());
        $this->assertSame('nonce="'.$csp->nonce().'"', $csp->attribute());
    }

    private function xsrfCookieIsHttpOnly(): bool
    {
        foreach ($this->get('https://localhost/probe')->headers->getCookies() as $cookie) {
            if ($cookie->getName() === 'XSRF-TOKEN') {
                return $cookie->isHttpOnly();
            }
        }

        $this->fail('The response carried no XSRF-TOKEN cookie to assert on.');
    }
}
