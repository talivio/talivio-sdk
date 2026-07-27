<?php

declare(strict_types=1);

namespace Talivio\Sdk\Ai\Migration;

use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;
use Talivio\Sdk\Ai\Contracts\TextClient;
use Throwable;

/**
 * GÖLGE KOŞU: eski yol ile ağ geçidi aynı istemle çağrılır, çıktılar
 * karşılaştırılır ve loglanır (talivio-ai Faz 2 kuralı: "karşılaştırmasız göç
 * yapılmaz").
 *
 * NEDEN SDK'DA: bu katmanı her ürüne kopyalamak, göç etmeye çalıştığımız
 * sorunun ta kendisi olurdu — envanterde aynı `GeminiClient` en az on projeye
 * kopyalanmıştı ve kopyalar birbirinden sapmıştı (farklı varsayılan model,
 * farklı hata mesajları). Göç aracının kendisi kopyalanırsa aynı sapma bu kez
 * "gölge koşu" adıyla tekrarlanır.
 *
 * ⚠️ HANGİSİNİN SONUCU KULLANILIR: `talivio-ai.migration.primary`. Gölge yol
 * yalnızca ÖLÇÜLÜR; hatası kullanıcıya ASLA yansımaz.
 *
 * ⚠️ BEDELİ AÇIK: her çağrı İKİ KEZ ödenir. Bu yüzden gölge koşu geçicidir ve
 * bayrakla kapatılır. Ücretsiz sanmak, göç süresince faturayı sessizce ikiye
 * katlardı.
 */
final class ShadowTextClient implements TextClient
{
    /**
     * Ölçüm SENTETİK mi (probe komutu) yoksa GERÇEK trafikten mi geldi?
     *
     * ⚠️ Ayrım şart. Ölçüldü (2026-07-27): altı üründe gölge kayıtları
     * kendiliğinden BİRİKMİYOR — samplio/VoxSim/rivo'da hiç yoktu, çünkü AI
     * yolları seyrek tetikleniyor (destek bileti, belge analizi, sürüş
     * asistanı). `talivio:ai-migration-probe` bu boşluğu dolduruyor ama
     * sentetik satırlar gerçeğin YERİNE geçmez: uzun bağlam, bozuk girdi,
     * araç turu gibi kenar durumları taşımazlar. Ayrıştırılmazsa "20 örnek
     * toplandı" diyen bir rapor aslında tek istemin 20 tekrarı olur ve göç
     * kararı sahte bir güvene dayanır.
     *
     * Statik: karşılaştırma `generateText`/`generateJson`'ın derininde yazılıyor
     * ve o imzalar eski istemcilerle BİREBİR aynı kalmak zorunda (sözleşme),
     * yani ek parametre geçirilemez.
     */
    public static bool $synthetic = false;

    public function __construct(
        private readonly TextClient $legacy,
        private readonly TextClient $gateway,
    ) {}

    public function enabled(): bool
    {
        return $this->primary()->enabled();
    }

    public function model(): string
    {
        return $this->primary()->model();
    }

    public function generateText(string $prompt, ?string $system = null, array $opts = []): ?string
    {
        $primary = $this->primary()->generateText($prompt, $system, $opts);

        $this->shadow(
            fn (TextClient $c): ?string => $c->generateText($prompt, $system, $opts),
            fn (?string $shadow): array => [
                'birincil_uzunluk' => $primary === null ? null : mb_strlen($primary),
                'golge_uzunluk' => $shadow === null ? null : mb_strlen($shadow),
                'birincil_bos' => $primary === null,
                'golge_bos' => $shadow === null,
            ],
            'text',
        );

        return $primary;
    }

    public function generateJson(string $prompt, ?string $system = null, array $opts = []): ?array
    {
        $primary = $this->primary()->generateJson($prompt, $system, $opts);

        $this->shadow(
            fn (TextClient $c): ?array => $c->generateJson($prompt, $system, $opts),
            fn (?array $shadow): array => [
                /*
                 * ⚠️ İÇERİK LOGLANMAZ, YALNIZCA ŞEKİL. Destek biletleri ve
                 * müşteri metinleri kişisel veri taşıyor; karşılaştırma için
                 * anahtar listesi yeterli. İçeriği loglamak, göç ölçümünü bir
                 * veri sızıntısına çevirirdi (talivio-ai ADR-19'un ruhu).
                 */
                'birincil_anahtarlar' => $primary === null ? null : array_keys($primary),
                'golge_anahtarlar' => $shadow === null ? null : array_keys($shadow),
                'ayni_anahtarlar' => $primary !== null && $shadow !== null
                    && array_keys($primary) === array_keys($shadow),
                'birincil_bos' => $primary === null,
                'golge_bos' => $shadow === null,
            ],
            'json',
        );

        return $primary;
    }

    /**
     * Gölge çağrıyı çalıştırır ve SONUCU ATAR.
     *
     * @param  callable(TextClient): mixed  $run
     * @param  callable(mixed): array<string, mixed>  $compare
     */
    private function shadow(callable $run, callable $compare, string $kind): void
    {
        $secondary = $this->secondary();

        if ($secondary === null) {
            return;
        }

        $startedAt = microtime(true);

        try {
            $shadow = $run($secondary);
        } catch (Throwable $e) {
            /*
             * Gölge yolun hatası ASLA yukarı sızmaz. Sızsaydı, göç ölçümü
             * çalışan bir ürünü bozardı — ve göçün amacı tam tersi.
             */
            $this->log()->warning('ai.golge_hata', [
                'tur' => $kind,
                'mesaj' => mb_substr($e->getMessage(), 0, 200),
            ]);

            return;
        }

        $this->log()->info('ai.golge_karsilastirma', [
            'tur' => $kind,
            'birincil_yol' => $this->primaryName(),
            'golge_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'sentetik' => self::$synthetic,
            ...$compare($shadow),
        ]);
    }

    /**
     * Göç ölçümü KENDİ KANALINA yazar.
     *
     * ⚠️ ÜRETİMDE `LOG_LEVEL=error` OLABİLİR (vatlio'da öyleydi, 2026-07-26):
     * `Log::info` ile yazılan karşılaştırma satırları hiç kaydedilmiyordu ve
     * gölge koşu "çalışmıyor" gibi görünüyordu — oysa çalışıyordu, ölçümü
     * göremiyorduk. Ölçümü uygulamanın log seviyesine bağlamak, göçün tek
     * çıktısını sessizce yok eder.
     *
     * Kanal runtime'da kuruluyor: ürünlerin `config/logging.php`'sine dokunmak
     * 18 projede 18 ayrı düzenleme demek olurdu.
     */
    private function log(): LoggerInterface
    {
        return Log::build([
            'driver' => 'daily',
            'path' => storage_path('logs/ai-migration.log'),
            'level' => 'info',
            // Göç geçici: iki hafta, karar vermeye fazlasıyla yeter.
            'days' => 14,
        ]);
    }

    private function primary(): TextClient
    {
        return $this->primaryName() === 'gateway' ? $this->gateway : $this->legacy;
    }

    private function secondary(): ?TextClient
    {
        if (! config('talivio-ai.migration.shadow', false)) {
            return null;
        }

        $secondary = $this->primaryName() === 'gateway' ? $this->legacy : $this->gateway;

        // Yapılandırılmamış bir gölge yolu çağırmak, her istekte boş bir
        // karşılaştırma satırı üretirdi.
        return $secondary->enabled() ? $secondary : null;
    }

    private function primaryName(): string
    {
        return (string) config('talivio-ai.migration.primary', 'legacy');
    }
}
