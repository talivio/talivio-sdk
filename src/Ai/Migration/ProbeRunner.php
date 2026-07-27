<?php

declare(strict_types=1);

namespace Talivio\Sdk\Ai\Migration;

/**
 * talivio-ai 2.MIG.25 — göç ölçümünü `TextClient` SÖZLEŞMESİNDEN AYIRIR.
 *
 * ⚠️ NEDEN GEREKTİ (iş sahibi sorusu: "tüm projelerin standart bir yapıda
 * olması doğru olmaz mı?"): gölge koşu ve `talivio:ai-migration-probe` ikisi
 * de `ShadowTextClient`'a bağlıydı, o da `TextClient` istiyordu. Ama iki ürün
 * bu sözleşmeye sığmıyor:
 *   - VoxSim: toplu çağrı (`generateJsonBatch`, 50'lik öbekler)
 *   - rivo:   akış (ilk cümlede sesli yanıt) + araç döngüsü
 * İkisi de kendi istemcileriyle göç etti ve ölçüm altyapısının DIŞINDA kaldı —
 * o iki üründe hiç karşılaştırma birikmiyordu, yani göçleri kanıtlanamıyordu.
 *
 * Çözüm sözleşmeyi genişletmek DEĞİL (rivo'nun akış imzası yanlış olursa
 * asistan sürüş sırasında susar; bu risk ölçüm için alınmaz), ölçümü
 * sözleşmeden bağımsız kılmak: ürün kendi iki yolunu nasıl çalıştıracağını
 * kendisi bilir, SDK yalnızca sonuçları karşılaştırıp kaydeder.
 *
 * ⚠️ Uygulayan sınıf ASLA fırlatmamalı: ölçüm, çalışan bir ürünü bozamaz.
 * Bir yol başarısızsa `null` döndürün — bu bir bulgudur, hata değil.
 */
interface ProbeRunner
{
    /**
     * Temsilî istemi ESKİ ve YENİ yolda çalıştırır.
     *
     * @param  array<string, mixed>  $probe  Ürünün config'indeki tanım
     * @return array{legacy: mixed, gateway: mixed}
     */
    public function run(array $probe): array;
}
