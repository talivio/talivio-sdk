<?php

namespace Talivio\Sdk\Ingest;

use DateTimeInterface;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Ürün → hub iş olayları: üyelikler, abonelikler, satışlar ve üyelik
 * başvuruları.
 *
 * NEDEN VAR: hub bu dört ucu baştan beri sunuyordu ama SDK'da karşılığı yoktu.
 * Her ürün payload'ı elle kuruyor, alan adlarını hub'ın doğrulayıcısını
 * okuyarak tahmin ediyordu. Sonuç, sözleşme kaymasının en geniş yüzeyi:
 * `customer_email` yerine `email` yazan bir ürün 422 alıyor, (acceptJson
 * öncesi) bunu göremiyor ve panelde geliri eksik görünüyordu. Alan adları ve
 * sınırlar artık tek yerde — burada — sabit.
 *
 * Hata sözleşmesi ikiye ayrılır ve bu bilinçli:
 *  • Eksik/geçersiz zorunlu alan → `InvalidArgumentException`. Bu bir
 *    programlama hatasıdır, geliştirme sırasında yüksek sesle görülmelidir;
 *    sessizce yutulursa gelir kaydı hiç oluşmadan kaybolur.
 *  • Ağ/HTTP hatası → `false` döner, loglanır, istisna atılmaz. Hub'ın kapalı
 *    olması ürünün ödeme akışını kırmamalı.
 */
class TalivioOps
{
    public function __construct(private IngestClient $client) {}

    /** Hub `MemberIngestController` doğrulamasıyla birebir. */
    private const MEMBER = [
        'required' => ['external_id', 'kind', 'status'],
        'strings' => [
            'external_id' => 255, 'kind' => 50, 'status' => 50, 'email' => 255,
            'name' => 255, 'organization' => 255, 'country' => 10, 'plan' => 255,
            'signup_application_external_id' => 255, 'admin_url' => 2048,
        ],
        'dates' => ['joined_at', 'last_active_at', 'churned_at'],
    ];

    private const SUBSCRIPTION = [
        'required' => ['external_id', 'status'],
        'strings' => [
            'external_id' => 255, 'customer_email' => 255, 'customer_name' => 255,
            'plan' => 255, 'status' => 50, 'provider' => 50, 'currency' => 10,
            'billing_interval' => 20, 'admin_url' => 2048,
        ],
        'dates' => ['trial_ends_at', 'current_period_end', 'canceled_at', 'started_at'],
    ];

    private const SALE = [
        'required' => ['external_id', 'status'],
        'strings' => [
            'external_id' => 255, 'customer_email' => 255, 'customer_name' => 255,
            'product_name' => 255, 'currency' => 10, 'status' => 50,
            'provider' => 50, 'admin_url' => 2048,
        ],
        'dates' => ['purchased_at'],
    ];

    private const SIGNUP_APPLICATION = [
        'required' => ['external_id', 'email', 'full_name', 'status'],
        'strings' => [
            'external_id' => 255, 'email' => 255, 'full_name' => 255,
            'organization' => 255, 'role_title' => 255, 'country' => 10,
            'requested_plan' => 255, 'message' => 5000, 'status' => 50,
            'admin_url' => 2048,
        ],
        'dates' => ['submitted_at', 'reviewed_at'],
    ];

    /**
     * Hub `kind` alanını serbest metin DEĞİL, sabit bir kümeyle doğruluyor.
     * Yanlış bir değer 422 ile döner; burada erkenden yakalamak, geliştiricinin
     * hatayı üretim loglarında değil ilk çalıştırmada görmesini sağlar.
     */
    private const MEMBER_KINDS = ['signup', 'application', 'install', 'manual'];

    /**
     * Üyelik yaşam döngüsü olayı (kayıt, plan değişimi, ayrılma, kurulum...).
     *
     * Zorunlu: `external_id`, `kind` (signup|application|install|manual),
     * `status`. `status` ürüne özgü serbest metindir — panelde tanınması için
     * hub'ın `config/talivio-statuses.php` sözlüğüyle uyumlu bir değer seçin.
     */
    public function member(array $data): bool
    {
        $kind = $data['kind'] ?? null;

        if (! in_array($kind, self::MEMBER_KINDS, true)) {
            throw new InvalidArgumentException(
                "TalivioOps::member() — `kind` şunlardan biri olmalı: ".implode(', ', self::MEMBER_KINDS)."; '{$kind}' verildi."
            );
        }

        return $this->dispatch('members', $data, self::MEMBER, 'member');
    }

    /** Abonelik yaşam döngüsü olayı (oluşturuldu, güncellendi, iptal...). */
    public function subscription(array $data): bool
    {
        return $this->dispatch('subscriptions', $data, self::SUBSCRIPTION, 'subscription');
    }

    /** Tek seferlik satın alma olayı (beklemede, ödendi, iade...). */
    public function sale(array $data): bool
    {
        return $this->dispatch('sales', $data, self::SALE, 'sale');
    }

    /** Üyelik/başvuru formu olayı (gönderildi, incelendi, onaylandı...). */
    public function signupApplication(array $data): bool
    {
        return $this->dispatch('signup-applications', $data, self::SIGNUP_APPLICATION, 'signupApplication');
    }

    private function dispatch(string $endpoint, array $data, array $schema, string $method): bool
    {
        foreach ($schema['required'] as $field) {
            if (($data[$field] ?? null) === null || $data[$field] === '') {
                throw new InvalidArgumentException("TalivioOps::{$method}() — `{$field}` zorunlu.");
            }
        }

        return $this->client->send($endpoint, $this->normalize($data, $schema));
    }

    /**
     * Alanları hub sınırlarına kırpar, tarihleri ISO-8601'e çevirir ve null
     * değerleri düşürür.
     *
     * Kırpma `mb_substr` ile: `substr` çok baytlı bir karakteri ortadan kesip
     * geçersiz UTF-8 üretebilir, o da gövdeyi `json_encode` aşamasında
     * patlatır — yani kırpmanın kendisi kaydın kaybolma sebebi olurdu.
     *
     * Şemada tanımsız anahtarlar OLDUĞU GİBİ geçirilir; hub bilmediği alanı
     * zaten yok sayar ve bu, hub'a yeni bir alan eklendiğinde SDK'yı
     * güncellemeden kullanabilmeyi mümkün kılar.
     */
    private function normalize(array $data, array $schema): array
    {
        $payload = [];

        foreach ($data as $key => $value) {
            if ($value === null) {
                continue;
            }

            if (in_array($key, $schema['dates'], true)) {
                $payload[$key] = $this->toIso($value);

                continue;
            }

            if (isset($schema['strings'][$key]) && is_string($value)) {
                $payload[$key] = mb_substr($value, 0, $schema['strings'][$key]);

                continue;
            }

            $payload[$key] = $value;
        }

        return $payload;
    }

    private function toIso(mixed $value): string
    {
        if ($value instanceof DateTimeInterface) {
            return Carbon::instance($value)->toIso8601String();
        }

        return Carbon::parse($value)->toIso8601String();
    }
}
