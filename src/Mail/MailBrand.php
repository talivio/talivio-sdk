<?php

namespace Talivio\Sdk\Mail;

/**
 * Ortak mail şablonunun marka değerlerini çözer.
 *
 * ⚠️ NEDEN AYRI BİR SINIF, NEDEN DOĞRUDAN config() DEĞİL: ürünlerin çoğu
 * `config/talivio.php`'yi vendor:publish ile KENDİ deposuna kopyalamış
 * durumda (2026-08-18'de 24 üründe böyleydi). Laravel'in `mergeConfigFrom`
 * birleştirmesi sığdır: uygulamanın `talivio.mail` dizisi SDK'nınkini
 * tamamen değiştirir. Yani SDK'ya yeni bir mail anahtarı eklemek, o 24
 * ürünün her birinde config dosyasını elle güncellemeden İŞE YARAMIYORDU —
 * logo boş, künye boş çıkıyordu.
 *
 * Buradaki sıra: yayınlanmış config → ortam değişkeni → paket varsayılanı.
 * Böylece eski config'li bir ürün yalnız `composer update` ile doğru
 * görünümü alır; env'e dokunmak isteyen ürün de dokunabilir.
 */
final class MailBrand
{
    public const DEFAULT_LOGO = 'https://talivio.com/assets/images/icon-logo.png';

    public const DEFAULT_LOGO_ICON = 'https://talivio.com/assets/images/icon-mail-64.png';

    public static function logo(): ?string
    {
        return self::value('brand_logo', 'TALIVIO_MAIL_LOGO', self::DEFAULT_LOGO);
    }

    public static function logoIcon(): ?string
    {
        return self::value('brand_logo_icon', 'TALIVIO_MAIL_LOGO_ICON', self::DEFAULT_LOGO_ICON);
    }

    public static function logoHeight(): int
    {
        return (int) (self::value('brand_logo_height', 'TALIVIO_MAIL_LOGO_HEIGHT', 40) ?: 40);
    }

    public static function color(): string
    {
        return (string) (self::value('brand_color', 'TALIVIO_MAIL_COLOR', '#0f172a') ?: '#0f172a');
    }

    /** Ürün adı: mail başlığının sağ hücresi ve logonun alt metni. */
    public static function productName(): string
    {
        return (string) (self::value('product_name', 'TALIVIO_MAIL_PRODUCT', null)
            ?: config('app.name', 'Talivio'));
    }

    public static function productUrl(): string
    {
        return (string) (self::value('product_url', 'TALIVIO_MAIL_PRODUCT_URL', null)
            ?: config('app.url', 'https://talivio.com'));
    }

    /**
     * Sağ hücrede yazan etiket. Boşsa ürün adına düşer — 2026-08-18 kararı:
     * tek tip "Support" etiketi 30+ üründen gelen maili ayırt edilemez
     * kılıyordu. talivio.com kendi kurumsal maillerinde bunu "Support"
     * yapar; orada ürün adı zaten soldaki logoda yazıyor.
     */
    public static function headerRight(): string
    {
        return (string) (self::value('header_right', 'TALIVIO_MAIL_HEADER_RIGHT', null) ?: self::productName());
    }

    public static function supportEmail(): string
    {
        return (string) (self::value('support_email', 'TALIVIO_MAIL_SUPPORT', 'support@talivio.com') ?: 'support@talivio.com');
    }

    /** @return array{company:string,address:string,vat_id:string} */
    public static function legal(): array
    {
        $legal = config('talivio.mail.legal');
        $legal = is_array($legal) ? $legal : [];

        return [
            'company' => (string) ($legal['company'] ?? env('TALIVIO_MAIL_LEGAL_COMPANY') ?: 'Talivio Technology OÜ'),
            'address' => (string) ($legal['address'] ?? env('TALIVIO_MAIL_LEGAL_ADDRESS') ?: 'Ahtri tn 12, Kesklinna linnaosa, 15551 Tallinn, Estonia'),
            'vat_id' => (string) ($legal['vat_id'] ?? env('TALIVIO_MAIL_LEGAL_VAT') ?: 'EE102744206'),
        ];
    }

    /**
     * config → env → paket varsayılanı.
     *
     * ⚠️ BOŞ DEĞER "AYARLANMAMIŞ" SAYILIR. Eski yayınlanmış config'lerde
     * `'brand_logo' => env('TALIVIO_MAIL_LOGO')` satırı VAR ama değeri
     * null; anahtarın varlığına bakmak, düzeltmek istediğim tam da o
     * durumu "ürün bilinçli olarak logosuz istedi" diye okurdu.
     */
    private static function value(string $key, string $env, mixed $default): mixed
    {
        $mail = config('talivio.mail');
        $configured = is_array($mail) ? ($mail[$key] ?? null) : null;

        if ($configured !== null && $configured !== '') {
            return $configured;
        }

        return env($env, $default);
    }
}
