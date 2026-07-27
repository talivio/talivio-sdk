<?php

declare(strict_types=1);

namespace Talivio\Sdk\Ai\Migration;

use Illuminate\Console\Command;
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

        $client = app(TextClient::class);

        if (! $client instanceof ShadowTextClient) {
            $this->components->error(sprintf(
                'TextClient gölge istemciye bağlı değil (%s) — karşılaştırma yazılmaz.',
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
