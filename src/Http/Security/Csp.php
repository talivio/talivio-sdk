<?php

namespace Talivio\Sdk\Http\Security;

use Illuminate\Support\Str;

/**
 * İstek başına Content-Security-Policy — ve o politikanın dayandığı nonce.
 *
 * NEDEN SDK'DA: 2026-09-06'da TCSR kendi ürünlerimizin 12'sini birden C'ye
 * düşürdü ve üçünde de aynı iki bulgu vardı ("CSP allows scripts from
 * any/broad origin", "CSP allows 'unsafe-inline' scripts"). Sebep tek bir
 * gevşek politikanın her yerde tekrar etmesiydi: talivio.com, canopyproof,
 * vatlio ve restockio'da BİRBİRİNİN KOPYASI dört SecurityHeaders middleware'i
 * duruyordu, kalan ürünlerde hiç yoktu. Politika bir üründe düzeltilip
 * ötekilerde unutulabildiği sürece bu tablo geri gelir; o yüzden politika
 * paketin içinde, ürünlerde yalnız kendi kaynakları kalıyor.
 *
 * ⚠️ NONCE, İZİN LİSTESİNİN YERİNE GEÇER. Eski kopyalarda script-src her CDN'i
 * tek tek sayıyor ve üstüne 'unsafe-inline' + 'unsafe-eval' veriyordu — yani
 * XSS'e karşı pratikte hiçbir şey yapmıyordu. Burada satır içi her <script>
 * kendi nonce'unu taşır (@talivioNonce), izin listesi de yalnızca ürünün
 * gerçekten kullandığı kökenlerden oluşur.
 *
 * ⚠️ style-src'de 'unsafe-inline' BİLEREK duruyor. Alpine (`x-show`), Livewire
 * ve pek çok kütüphane element.style'a yazar; bunlar style-src-attr'a girer ve
 * nonce ile imzalanamaz. Satır içi stil, satır içi script'in taşıdığı riski
 * taşımaz — sıkı olan yer script-src.
 */
class Csp
{
    private ?string $nonce = null;

    /** Bu isteğin nonce'u; ilk sorulduğunda üretilir, istek boyunca aynı kalır. */
    public function nonce(): string
    {
        return $this->nonce ??= Str::random(40);
    }

    /** Blade'in `@talivioNonce` ile bastığı hâli. */
    public function attribute(): string
    {
        return 'nonce="'.$this->nonce().'"';
    }

    public function enabled(): bool
    {
        return (bool) config('talivio.security.csp.enabled', true);
    }

    /**
     * Uygulanan mı yoksa yalnız rapor edilen mi.
     *
     * Bir ürüne ilk açılışta rapor kipinde açmak, sayfaları kırmadan hangi
     * kaynağın eksik olduğunu tarayıcı konsolundan görmeyi sağlar.
     */
    public function headerName(): string
    {
        return config('talivio.security.csp.report_only', false)
            ? 'Content-Security-Policy-Report-Only'
            : 'Content-Security-Policy';
    }

    public function header(): string
    {
        $parts = [];

        foreach ($this->directives() as $name => $sources) {
            $sources = array_values(array_unique(array_filter(array_map('strval', $sources), fn ($s) => $s !== '')));

            if ($sources === []) {
                continue;
            }

            $parts[] = $name.' '.implode(' ', $sources);
        }

        if ($uri = config('talivio.security.csp.report_uri')) {
            $parts[] = 'report-uri '.$uri;
        }

        return implode('; ', $parts);
    }

    /**
     * Varsayılan politika + ürünün eklemeleri.
     *
     * İki ayrı kapı var, çünkü iki ayrı ihtiyaç: `sources` bir yönergeye
     * kaynak EKLER (ürünlerin %90'ının yaptığı — Stripe, Google Fonts, bir
     * gömülü oynatıcı), `directives` bir yönergeyi tamamen DEĞİŞTİRİR
     * (Shopify gömülü uygulamalarının frame-ancestors'ı gibi, varsayılanın
     * doğru olmadığı hâller).
     *
     * @return array<string, list<string>>
     */
    public function directives(): array
    {
        $directives = $this->defaults();

        foreach ((array) config('talivio.security.csp.directives', []) as $name => $sources) {
            $directives[$name] = (array) $sources;
        }

        foreach ((array) config('talivio.security.csp.sources', []) as $name => $sources) {
            $directives[$name] = array_merge($directives[$name] ?? [], (array) $sources);
        }

        $directives['script-src'][] = "'nonce-".$this->nonce()."'";

        /*
         * 'strict-dynamic' varsayılan olarak KAPALI. Açıkken CSP3 tarayıcıları
         * script-src'deki köken listesini tamamen yok sayar — nonce taşımayan
         * her <script src> susar. Ürünlerin satır içi script'lerini nonce'a
         * geçirmesi tek seferlik bir iş; harici script'lerin hepsini de aynı
         * anda nonce'a geçirmek ise sayfayı sessizce kırmanın en kolay yolu.
         * Ürün hazır olduğunda açar.
         */
        if (config('talivio.security.csp.strict_dynamic', false)) {
            $directives['script-src'][] = "'strict-dynamic'";
        }

        return $directives;
    }

    /** @return array<string, list<string>> */
    private function defaults(): array
    {
        // Ürünlerin çoğu SDK'nın kendi varlıklarını (analytics t.js, human-check)
        // hub'dan çekiyor; her ürüne tek tek yazdırmak yerine burada duruyor.
        $hub = rtrim((string) config('talivio.hub_url', 'https://talivio.com'), '/');

        return [
            'default-src' => ["'self'"],
            'base-uri' => ["'self'"],
            'object-src' => ["'none'"],
            'form-action' => ["'self'"],
            'frame-ancestors' => ["'self'"],
            'script-src' => ["'self'", $hub],
            'style-src' => ["'self'", "'unsafe-inline'"],
            'img-src' => ["'self'", 'data:'],
            'font-src' => ["'self'", 'data:'],
            'connect-src' => ["'self'", $hub],
            'frame-src' => ["'self'"],
        ];
    }
}
