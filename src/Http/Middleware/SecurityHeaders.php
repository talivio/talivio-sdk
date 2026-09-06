<?php

namespace Talivio\Sdk\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\Response;
use Talivio\Sdk\Http\Security\Csp;
use Throwable;

/**
 * Sıkı bir sitenin göndermesi beklenen yanıt başlıkları — tek yerden.
 *
 * Ürüne eklemek: bootstrap/app.php içinde
 *   $middleware->web(prepend: [\Talivio\Sdk\Http\Middleware\SecurityHeaders::class]);
 *
 * ⚠️ APPEND DEĞİL, PREPEND. Prepend edilince EN DIŞTA durur ve yanıt geri
 * dönerken en SON o çalışır — yani XSRF-TOKEN çerezi kuyruğa girdikten sonra.
 * Append edilirse çerez daha üretilmemiş olur ve harden_xsrf_cookie sessizce
 * hiçbir şey yapmaz (talivio.com bunu bir kez yaşadı).
 *
 * ⚠️ VAR OLAN BAŞLIK EZİLMEZ — CSP HARİÇ. Bu ürünlerin nginx'i zaten
 * X-Frame-Options ve X-Content-Type-Options gönderiyor; aynı başlığı bir de
 * buradan yazmak yinelenmiş başlık üretir ve bazı tarayıcılar yinelenen
 * X-Frame-Options'ı "ikisi de geçersiz" sayar.
 *
 * ⚠️ CSP'yi ise EZER, ve bu bilinçli. İki farklı CSP aynı yanıtta bir tercih
 * değil, bir çakışmadır; sessizce kaybeden taraf olmak en kötü sonuç. Dört
 * Shopify uygulamamızda tam bu yaşandı: kyon147/laravel-shopify'ın
 * IframeProtection'ı içeride kendi (yalnız frame-ancestors'lı, nonce'suz)
 * politikasını yazıyor, bu middleware "başlık var" deyip çekiliyor ve sıkı
 * politika hiç uygulanmıyordu — TCSR bulgusu da düzelmiş görünürken duruyordu.
 * Bu middleware EN DIŞTA durduğu için son sözü onun söylemesi zaten tasarımın
 * amacı. Kendi politikasını yazmak isteyen ürün `talivio.security.csp.enabled`
 * ile bunu kapatır.
 *
 * ⚠️ X-Frame-Options BİLEREK HİÇ GÖNDERİLMİYOR. Clickjacking'i CSP'nin
 * frame-ancestors'ı karşılıyor ve o, XFO'nun anlatamadığı şeyi anlatabiliyor:
 * Shopify gömülü uygulamalarımızın admin.shopify.com içinde açılması gerekiyor,
 * XFO ile bunu söylemenin yolu yok. İkisini birden göndermek o ürünlerde
 * gömülü ekranı kırardı.
 */
class SecurityHeaders
{
    public function __construct(private readonly Csp $csp) {}

    public function handle(Request $request, Closure $next): Response
    {
        /*
         * Nonce yanıt üretilmeden ÖNCE Vite'a veriliyor: `@vite(...)` ürettiği
         * <script>/<link> etiketlerine nonce'u kendisi basar, yoksa derlenmiş
         * paketler 'strict-dynamic' açıldığı anda susardı.
         */
        $this->shareNonce();

        $response = $next($request);

        foreach ($this->headers($request) as $name => $value) {
            if ($value !== null && $value !== '' && ! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        if ($this->csp->enabled()) {
            $response->headers->set($this->csp->headerName(), $this->csp->header());
        }

        $this->hardenXsrfCookie($response);

        return $response;
    }

    /** @return array<string, string|null> */
    private function headers(Request $request): array
    {
        $headers = [
            // HSTS yalnızca HTTPS yanıtında anlamlı; düz HTTP üzerinde
            // tarayıcı zaten yok sayar, yerel geliştirmede ise siteyi
            // kalıcı olarak https'e kilitleyip erişilemez hâle getirir.
            'Strict-Transport-Security' => $request->secure()
                ? config('talivio.security.hsts', 'max-age=31536000; includeSubDomains; preload')
                : null,
            'Referrer-Policy' => config('talivio.security.referrer_policy', 'strict-origin-when-cross-origin'),
            'Permissions-Policy' => config('talivio.security.permissions_policy', 'geolocation=(), camera=(), microphone=(), payment=(), usb=()'),
            'X-Content-Type-Options' => config('talivio.security.content_type_options', true) ? 'nosniff' : null,
        ];

        return $headers;
    }

    /**
     * XSRF-TOKEN çerezine HttpOnly ekler — YALNIZCA ürün açıkça istediyse.
     *
     * Blade formları jetonu @csrf alanından alır, çerezi okumaya ihtiyaçları
     * yoktur. Ama axios/Livewire'ın bazı kurulumları jetonu ÇEREZDEN okur;
     * varsayılan olarak açmak o ürünlerde bütün POST'ları 419'a düşürürdü.
     */
    private function hardenXsrfCookie(Response $response): void
    {
        if (! config('talivio.security.harden_xsrf_cookie', false)) {
            return;
        }

        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === 'XSRF-TOKEN' && ! $cookie->isHttpOnly()) {
                $response->headers->setCookie($cookie->withHttpOnly(true));
            }
        }
    }

    /**
     * Nonce'u kendi <script> etiketini ÜRETEN katmanlara verir.
     *
     * Vite ve Livewire etiketlerini şablonda değil kendi içlerinde üretiyor;
     * ürün onlara nonce ekleyemez. İkisi de bunun için bir kanca sunuyor ve
     * SDK bu kancaları ürünlerin adına bağlıyor — yoksa CSP'yi açan her
     * Livewire ürünü aynı hatayı (livewire'ın satır içi yapılandırma
     * script'i susuyor) tek tek keşfederdi.
     */
    private function shareNonce(): void
    {
        if (! $this->csp->enabled()) {
            return;
        }

        $nonce = $this->csp->nonce();

        try {
            if (class_exists(Vite::class)) {
                Vite::useCspNonce($nonce);
            }
        } catch (Throwable) {
            // Bu katmanlar yoksa/başka biçimde yapılandırıldıysa güvenlik
            // başlıkları yine de gönderilmeli.
        }

        try {
            if (class_exists(Livewire::class)) {
                Livewire::useScriptTagAttributes(['nonce' => $nonce]);
            }
        } catch (Throwable) {
            // aynı gerekçe
        }
    }
}
