<?php

declare(strict_types=1);

namespace Talivio\Sdk\Ai\Migration;

use Illuminate\Console\Command;

/**
 * Gölge koşu ölçümünü okunabilir bir özete çevirir (talivio-ai Faz 2).
 *
 * NEDEN KOMUT: göç kararı ("birincil yolu ağ geçidine çevirelim mi") log
 * dosyasını gözle tarayarak verilemez — kaç örnek var, kaçında iki yol aynı
 * şekli üretti, gölge yol ne kadar yavaş, kaç kez patladı. Bu sorulara cevap
 * vermeyen bir gölge koşu, ödenen ikinci faturanın karşılığını hiç almaz.
 */
final class MigrationReportCommand extends Command
{
    protected $signature = 'talivio:ai-migration-report {--days=7 : Kaç günlük log okunsun}';

    protected $description = 'Talivio AI göçünün gölge koşu ölçümünü özetler';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $satirlar = $this->satirlar($days);

        if ($satirlar === []) {
            $this->warn('Hiç karşılaştırma kaydı yok.');
            $this->line('Olası sebepler: gölge koşu kapalı (TALIVIO_AI_SHADOW), '
                .'henüz hiç AI çağrısı yapılmadı, ya da ağ geçidi anahtarı tanımsız.');

            return self::SUCCESS;
        }

        $this->ozet($satirlar);

        return self::SUCCESS;
    }

    /** @return list<array<string, mixed>> */
    private function satirlar(int $days): array
    {
        $kayitlar = [];

        for ($i = 0; $i < $days; $i++) {
            $yol = storage_path('logs/ai-migration-'.now()->subDays($i)->format('Y-m-d').'.log');

            if (! is_file($yol)) {
                continue;
            }

            foreach (file($yol, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $satir) {
                /*
                 * Log satırından JSON bağlamını çıkarmak: Monolog'un satır
                 * biçimi "[tarih] kanal.SEVIYE: mesaj {json}" — ilk `{`'ten
                 * sonrası bağlam. Ayrıştırılamayan satır sessizce atlanır;
                 * bir biçim değişikliği yüzünden komutun patlaması, ölçümü
                 * okunamaz hâle getirmekten kötü olurdu.
                 */
                $bas = mb_strpos($satir, '{');

                if ($bas === false || ! str_contains($satir, 'ai.golge')) {
                    continue;
                }

                $veri = json_decode(mb_substr($satir, $bas), true);

                if (is_array($veri)) {
                    $veri['_hata'] = str_contains($satir, 'ai.golge_hata');
                    $kayitlar[] = $veri;
                }
            }
        }

        return $kayitlar;
    }

    /** @param list<array<string, mixed>> $satirlar */
    private function ozet(array $satirlar): void
    {
        $hatalar = array_filter($satirlar, fn (array $s): bool => $s['_hata'] === true);
        $karsilastirmalar = array_filter($satirlar, fn (array $s): bool => $s['_hata'] === false);

        $json = array_filter($karsilastirmalar, fn (array $s): bool => ($s['tur'] ?? '') === 'json');
        $ayni = array_filter($json, fn (array $s): bool => ($s['ayni_anahtarlar'] ?? false) === true);

        $golgeBos = array_filter($karsilastirmalar, fn (array $s): bool => ($s['golge_bos'] ?? false) === true);
        $birincilBos = array_filter($karsilastirmalar, fn (array $s): bool => ($s['birincil_bos'] ?? false) === true);

        $sureler = array_values(array_filter(array_map(
            fn (array $s): ?int => isset($s['golge_ms']) ? (int) $s['golge_ms'] : null,
            $karsilastirmalar,
        )));

        sort($sureler);

        /*
         * ⚠️ SENTETİK VE GERÇEK AYRI SAYILIR (2.MIG.24).
         *
         * `talivio:ai-migration-probe` ölçümü gerçek trafiği beklemeden
         * biriktirebiliyor — gerekliydi, çünkü altı üründe kayıt kendiliğinden
         * birikmiyordu. Ama sentetik istemler kenar durumları (uzun bağlam,
         * bozuk girdi, araç turu) taşımaz. Tek sayıda toplasaydık "20 örnek"
         * diyen bir rapor aslında tek istemin 20 tekrarı olabilirdi ve göç
         * kararı sahte bir güvene dayanırdı.
         */
        $sentetik = array_filter($karsilastirmalar, static fn (array $s): bool => ($s['sentetik'] ?? false) === true);
        $gercek = count($karsilastirmalar) - count($sentetik);

        $this->table(['Ölçüm', 'Değer'], [
            ['Karşılaştırma sayısı', count($karsilastirmalar)],
            ['— gerçek trafik', $gercek],
            ['— sentetik (probe)', count($sentetik)],
            ['Gölge yol HATASI', count($hatalar)],
            ['Birincil boş döndü', count($birincilBos)],
            ['Gölge boş döndü', count($golgeBos)],
            ['JSON çağrısı', count($json)],
            ['— aynı anahtar kümesi', count($json) === 0 ? '—' : count($ayni).' / '.count($json)],
            ['Gölge medyan (ms)', $sureler === [] ? '—' : $sureler[intdiv(count($sureler), 2)]],
            ['Gölge en yavaş (ms)', $sureler === [] ? '—' : end($sureler)],
        ]);

        /*
         * ⚠️ KARAR OTOMATİK VERİLMEZ, yalnızca önerilir. "Şekil aynı" demek
         * "içerik doğru" demek değil — yalnızca ayrıştırılabilirliği ölçüyoruz
         * (ADR-19 gereği içeriği loglamıyoruz). Son kararı insan verir.
         */
        $this->newLine();

        if (count($karsilastirmalar) < 20) {
            $this->warn('Örnek sayısı düşük (<20): karar için erken.');

            return;
        }

        /*
         * ⚠️ SENTETİK ÇOĞUNLUKTA OLAN BİR ÖLÇÜM GÖÇ KARARINI TAŞIMAZ.
         * Eşik sayısal bir kesinlik iddiası değil; "bu tablo ağırlıkla kendi
         * ürettiğimiz istemlerden oluşuyor" uyarısıdır.
         */
        if ($gercek < count($karsilastirmalar) * 0.5) {
            $this->warn(sprintf(
                'Ölçümün çoğu SENTETİK (%d gerçek / %d toplam) — probe istemleri kenar '
                .'durumları taşımaz. Gerçek trafik birikmeden birincili çevirmeyin.',
                $gercek,
                count($karsilastirmalar),
            ));

            return;
        }

        if (count($hatalar) > 0 || count($golgeBos) > count($karsilastirmalar) * 0.05) {
            $this->error('Gölge yol kararsız — birincili çevirmeden önce sebebi bulun.');

            return;
        }

        if (count($json) > 0 && count($ayni) < count($json) * 0.95) {
            $this->error('JSON şekli %95’in altında eşleşiyor — istem/şema farkı incelenmeli.');

            return;
        }

        $this->info('Ölçüm temiz görünüyor. TALIVIO_AI_PRIMARY=gateway denenebilir.');
        $this->line('⚠️ Şekil eşleşmesi içerik doğruluğu DEĞİLDİR; birkaç yanıtı gözle de kontrol edin.');
    }
}
