<?php

declare(strict_types=1);

/**
 * Talivio AI istemci yapılandırması — İSTEMCİ PROJELERE kurulur.
 *
 * ⚠️ Bu dosyada SAĞLAYICI ANAHTARI YOKTUR ve olmayacaktır (ADR-17).
 * Gemini/DeepSeek/DeepInfra anahtarları yalnızca ağ geçidinde bulunur; istemci
 * projede yalnızca ağ geçidi anahtarı (`TALIVIO_AI_KEY`) durur. Göç sırasında
 * eski sağlayıcı anahtarları istemcinin `.env`'inden SİLİNİR (0.KEY.06).
 */
return [

    'base_url' => env('TALIVIO_AI_BASE_URL', 'https://ai.talivio.com'),
    'key' => env('TALIVIO_AI_KEY'),

    /*
    | Sürücü: 'http' üretim, 'fake' test.
    |
    | fit_web'in `GEMINI_DRIVER=stub` deseninden alındı (envanter 2. tur):
    | istemci projelerin test paketi ASLA ağ geçidine çıkmamalı — çıkarsa
    | testler ağa ve bütçeye bağımlı hale gelir.
    */
    'driver' => env('TALIVIO_AI_DRIVER', 'http'),

    'timeout' => (int) env('TALIVIO_AI_TIMEOUT', 60),
    'connect_timeout' => (int) env('TALIVIO_AI_CONNECT_TIMEOUT', 5),

    /*
    |--------------------------------------------------------------------------
    | ZARİF BOZULMA (ADR-17) — bu bölüm isteğe bağlı DEĞİLDİR
    |--------------------------------------------------------------------------
    | Doğrudan sağlayıcı yolu yoktur: ağ geçidi erişilemezse istemci AI'sız
    | çalışmaya devam eder. Bu ayarlar "nasıl devam edeceğini" belirler.
    |
    |   'template' → önceden yazılmış AI'sız yedek yanıt kullanılır
    |                (ör. RiVo'nun sürüş güvenliği uyarısı — şablon uyarı)
    |   'queue'    → iş istemcinin kuyruğunda bekletilir, sonra tekrar denenir
    |   'disable'  → özellik sessizce kapanır (kullanıcıya hata gösterilmez)
    |
    | ⚠️ 'throw' BİLİNÇLİ OLARAK YOKTUR. İstisna fırlatmak, ağ geçidi arızasını
    | son kullanıcının ekranına taşımak demektir; merkezîleşmenin bedelini
    | kullanıcıya ödetmek kabul edilmedi.
    */
    'degradation' => [
        'mode' => env('TALIVIO_AI_DEGRADATION', 'disable'),
        'retries' => (int) env('TALIVIO_AI_RETRIES', 2),
        // Kısa devre: bu kadar üst üste hata sonrası ağ geçidine hiç gidilmez,
        // doğrudan bozulma davranışı uygulanır. Arızalı bir geçide her istekte
        // yeniden gitmek gecikmeyi kullanıcıya ödetir.
        'breaker_failures' => (int) env('TALIVIO_AI_BREAKER_FAILURES', 5),
        'breaker_cooldown_seconds' => (int) env('TALIVIO_AI_BREAKER_COOLDOWN', 60),
    ],

    /*
    | Kullanım kaydını istemci tarafında da logla. Ağ geçidi `usage.talivio`
    | bloğunda gerçekte hangi sağlayıcı/model çalıştığını ve maliyeti döner;
    | "neden bu yanıt böyle geldi" sorusu ancak bu kayıtla cevaplanır.
    */
    'log_usage' => (bool) env('TALIVIO_AI_LOG_USAGE', true),

    /*
    |--------------------------------------------------------------------------
    | Varsayılan kalite katmanı (tier)
    |--------------------------------------------------------------------------
    | ⚠️ MODEL ADI DEĞİL, TIER (talivio-ai ADR-04). Model seçimi ağ geçidinin
    | kararı; hangi modelin ucuz/hızlı/yetenekli olduğu merkezde ÖLÇÜLÜYOR ve
    | 18 projede ayrı ayrı güncellenmesi gerekmiyor.
    */
    'default_tier' => env('TALIVIO_AI_TIER', 'standard'),

    /*
    |--------------------------------------------------------------------------
    | Göç (gölge koşu) — talivio-ai Faz 2
    |--------------------------------------------------------------------------
    | `primary`: hangi yolun SONUCU kullanılır — 'legacy' (ürünün eski, doğrudan
    | sağlayıcı istemcisi) ya da 'gateway' (Talivio AI).
    | `shadow`: diğer yol da çağrılıp çıktılar karşılaştırılsın mı?
    |
    | ⚠️ GÖLGE KOŞU HER ÇAĞRIYI İKİ KEZ ÖDETİR. Geçicidir: ölçüm temizse
    | `primary` 'gateway' yapılır, sonra gölge kapatılır ve eski istemci silinir.
    | "Karşılaştırmasız göç yapılmaz" kuralının bedeli budur ve görünür olması
    | gerekir — ücretsiz sanmak, göç süresince faturayı sessizce ikiye katlardı.
    */
    'migration' => [
        /*
         * Ürünün ESKİ istemcisinin sınıf adı (ör. `App\Services\AI\GeminiClient`).
         * Tanımlıysa `TextClient` sözleşmesine GÖLGE KOŞU bağlanır; boşsa
         * doğrudan ağ geçidi bağlanır — yani göç bitmiş ürünlerde bu satır
         * silinerek eski yol tamamen devre dışı kalıyor.
         */
        'legacy' => env('TALIVIO_AI_LEGACY_CLIENT'),

        'primary' => env('TALIVIO_AI_PRIMARY', 'legacy'),
        'shadow' => (bool) env('TALIVIO_AI_SHADOW', false),
    ],
];
