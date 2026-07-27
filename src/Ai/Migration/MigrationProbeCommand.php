<?php

declare(strict_types=1);

namespace Talivio\Sdk\Ai\Migration;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Talivio\Sdk\Ai\Contracts\TextClient;

/**
 * talivio-ai 2.MIG.24 — göç ölçümünü gerçek trafiği BEKLEMEDEN biriktirir.
 *
 * ⚠️ NEDEN GEREKTİ: gölge koşu altı üründe kuruluydu ama ölçüm birikmiyordu.
 * 2026-07-27'de sayıldı — samplio, VoxSim ve rivo'da HİÇ karşılaştırma yoktu;
 * vatlio/canopyproof/invonio'daki birkaç satır da elle tetiklenmişti. Sebep
 * yapısal: bu ürünlerin AI yolları seyrek çalışıyor (destek bileti gelmesi,
 * belge analizi, sürüş asistanı). "Ölçüm birikince birincili çevir" planı bu
 * hızla aylarca ilerlemez, yani göç fiilen durur.
 *
 * ⚠️ SENTETİK ÖLÇÜM GERÇEĞİN YERİNE GEÇMEZ. Buradaki istemler temsilîdir;
 * uzun bağlam, bozuk girdi, araç turu gibi kenar durumları taşımazlar. Bu
 * yüzden yazdıkları satır `sentetik: true` ile işaretlenir ve rapor ikisini
 * ayrı sayar — aksi hâlde "20 örnek toplandı" diyen bir rapor aslında tek
 * istemin 20 tekrarı olur ve göç kararı sahte bir güvene dayanırdı.
 *
 * ⚠️ İSTEMLER ÜRÜNÜN KENDİSİNDEN GELİR (`talivio-ai.migration.probes`), bu
 * paketten değil: her ürünün AI işi farklı ve buradan uydurulmuş bir istem
 * "geçit uyumlu" derken ürünün asıl kullanımını hiç sınamamış olurdu.
 * ⚠️ İstemler GERÇEK MÜŞTERİ VERİSİ İÇERMEMELİ — bu komut zamanlanmış
 * çalışabilir ve karşılaştırma satırları log'a düşer (talivio-ai ADR-19).
 */
final class MigrationProbeCommand extends Command
{
    protected $signature = 'talivio:ai-migration-probe {--json : Yalnızca JSON istemlerini koştur}';

    protected $description = 'Temsilî istemlerle her iki AI yolunu koşturur ve gölge karşılaştırması biriktirir';

    /**
     * Ürünün kendi koşucusuyla ölçüm.
     *
     * ⚠️ Karşılaştırma İÇERİK DEĞİL ŞEKİL üzerinden yapılır (talivio-ai
     * ADR-19): anahtar kümesi, boşluk ve süre. Ürünlerin AI yolları müşteri
     * verisi taşıyor; ölçüm uğruna onu log'a yazmak göçü bir sızıntıya
     * çevirirdi.
     *
     * @param  list<array<string, mixed>>  $probes
     */
    private function runWithCustomRunner(array $probes, ProbeRunner $runner): int
    {
        $sayac = 0;

        foreach ($probes as $probe) {
            $ad = (string) ($probe['ad'] ?? 'isimsiz');
            $basladi = microtime(true);

            try {
                ['legacy' => $legacy, 'gateway' => $gateway] = $runner->run($probe);
            } catch (\Throwable $e) {
                /*
                 * Koşucunun hatası ölçümü durdurmaz ama SESSİZ de kalmaz:
                 * "ölçüm yok" ile "ölçüm başarısız" ayrı şeylerdir.
                 */
                Log::build(['driver' => 'single', 'path' => storage_path('logs/ai-migration.log')])
                    ->error('ai.golge_hata', ['ad' => $ad, 'mesaj' => mb_substr($e->getMessage(), 0, 200)]);

                $this->components->twoColumnDetail($ad, '<fg=red>koşucu hatası</>');

                continue;
            }

            Log::build(['driver' => 'single', 'path' => storage_path('logs/ai-migration.log')])
                ->info('ai.golge_karsilastirma', [
                    'tur' => is_array($legacy) ? 'json' : 'text',
                    'birincil_yol' => 'legacy',
                    'golge_ms' => (int) round((microtime(true) - $basladi) * 1000),
                    'sentetik' => true,
                    'birincil_anahtarlar' => is_array($legacy) ? array_keys($legacy) : null,
                    'golge_anahtarlar' => is_array($gateway) ? array_keys($gateway) : null,
                    'ayni_anahtarlar' => is_array($legacy) && is_array($gateway)
                        && array_keys($legacy) === array_keys($gateway),
                    'birincil_bos' => $legacy === null || $legacy === '' || $legacy === [],
                    'golge_bos' => $gateway === null || $gateway === '' || $gateway === [],
                ]);

            $this->components->twoColumnDetail($ad, '<fg=green>yazıldı</>');
            $sayac++;
        }

        $this->newLine();
        $this->components->info("{$sayac} temsilî istem koşturuldu (ürünün kendi koşucusuyla).");

        return self::SUCCESS;
    }

    public function handle(): int
    {
        $probes = (array) config('talivio-ai.migration.probes', []);

        if ($probes === []) {
            /*
             * Sessizce "0 istem koşturuldu" deyip başarı dönmek, ölçüm
             * birikmediğinde kimsenin fark etmemesine yol açardı — zaten bu
             * komutun var olma sebebi tam olarak o sessizlik.
             */
            $this->components->error(
                'talivio-ai.migration.probes BOŞ — koşturulacak temsilî istem yok. '
                .'Ürünün config/talivio-ai.php dosyasına ekleyin: '
                .'["probes" => [["ad" => "siniflandirma", "tur" => "json", "prompt" => "..."]]]. '
                .'⚠️ Gerçek müşteri verisi KULLANMAYIN.'
            );

            return self::FAILURE;
        }

        if (! config('talivio-ai.migration.shadow', false)) {
            $this->components->error(
                'Gölge koşu KAPALI (talivio-ai.migration.shadow=false) — bu komut '
                .'karşılaştırma üretemez, yalnız birincil yolu boşuna çalıştırırdı.'
            );

            return self::FAILURE;
        }

        /*
         * 2.MIG.25 — ÜRÜN KENDİ KOŞUCUSUNU VEREBİLİR.
         *
         * `ShadowTextClient` yolu `TextClient` sözleşmesini şart koşuyor; VoxSim
         * (toplu çağrı) ve rivo (akış + araç döngüsü) o sözleşmeye sığmadığı
         * için ölçüm altyapısının dışında kalmışlardı. Kendi `ProbeRunner`'ını
         * bildiren ürün artık kapsama giriyor.
         */
        $runnerClass = config('talivio-ai.migration.probe_runner');

        if (is_string($runnerClass) && $runnerClass !== '') {
            return $this->runWithCustomRunner($probes, app($runnerClass));
        }

        $client = app(TextClient::class);

        if (! $client instanceof ShadowTextClient) {
            $this->components->error(sprintf(
                'TextClient gölge istemciye bağlı değil (%s) ve `migration.probe_runner` '
                .'tanımlı değil — karşılaştırma yazılmaz. Kendi istemcisini kullanan '
                .'ürünler bir ProbeRunner bildirmeli (2.MIG.25).',
                $client::class,
            ));

            return self::FAILURE;
        }

        // Bu koşunun ürettiği HER satır sentetik işaretlenir; `finally` ile
        // geri alınır ki komut hata verse bile sonraki gerçek trafik yanlış
        // etiketlenmesin.
        ShadowTextClient::$synthetic = true;
        $basarili = 0;

        try {
            foreach ($probes as $probe) {
                $ad = (string) ($probe['ad'] ?? 'isimsiz');
                $tur = (string) ($probe['tur'] ?? 'text');
                $prompt = (string) ($probe['prompt'] ?? '');

                if ($prompt === '' || ($this->option('json') && $tur !== 'json')) {
                    continue;
                }

                $sonuc = $tur === 'json'
                    ? $client->generateJson($prompt, $probe['system'] ?? null)
                    : $client->generateText($prompt, $probe['system'] ?? null);

                /*
                 * ⚠️ Boş birincil yanıt burada HATA DEĞİL, bulgudur: ürünün
                 * kendi yolu da bozuk olabilir ve bunu göç ölçümüne borçluyuz.
                 * Komutun görevi karşılaştırmayı YAZDIRMAK; kararı rapor verir.
                 */
                $this->components->twoColumnDetail(
                    "{$ad} ({$tur})",
                    $sonuc === null ? '<fg=yellow>birincil boş</>' : '<fg=green>yazıldı</>',
                );

                $basarili++;
            }
        } finally {
            ShadowTextClient::$synthetic = false;
        }

        $this->newLine();
        $this->components->info("{$basarili} temsilî istem koşturuldu; karşılaştırmalar sentetik olarak işaretlendi.");
        $this->line('  Raporu okumak için: <fg=cyan>php artisan talivio:ai-migration-report</>');

        return self::SUCCESS;
    }
}
