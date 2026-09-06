<?php

namespace Talivio\Sdk\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
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
 * ⚠️ VAR OLAN BAŞLIK EZİLMEZ. Bu ürünlerin nginx'i zaten X-Frame-Options ve
 * X-Content-Type-Options gönderiyor; aynı başlığı bir de buradan yazmak
 * yinelenmiş başlık üretir ve bazı tarayıcılar yinelenen X-Frame-Options'ı
 * "ikisi de geçersiz" sayar.
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
        $this->shareNonceWithVite();

        $response = $next($request);

        foreach ($this->headers($request) as $name => $value) {
            if ($value !== null && $value !== '' && ! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
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

        if ($this->csp->enabled()) {
            $headers[$this->csp->headerName()] = $this->csp->header();
        }

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

    private function shareNonceWithVite(): void
    {
        if (! $this->csp->enabled() || ! class_exists(Vite::class)) {
            return;
        }

        try {
            Vite::useCspNonce($this->csp->nonce());
        } catch (Throwable) {
            // Vite bu üründe kurulu değilse/başka biçimde yapılandırıldıysa
            // güvenlik başlıkları yine de gönderilmeli.
        }
    }
}
