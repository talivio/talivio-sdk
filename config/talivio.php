<?php

use App\Models\User;

return [

    // The Talivio Accounts hub — talivio.com.
    'hub_url' => env('TALIVIO_HUB_URL', 'https://talivio.com'),

    // OAuth client issued from the "Talivio Ürünleri" Filament resource.
    'client_id' => env('TALIVIO_CLIENT_ID'),
    'client_secret' => env('TALIVIO_CLIENT_SECRET'),
    'redirect' => env('TALIVIO_REDIRECT_URI'), // defaults to {APP_URL}/talivio/callback

    // Ingest token for error/support telemetry (from the same Filament resource).
    'ingest_token' => env('TALIVIO_INGEST_TOKEN'),
    'telemetry_enabled' => env('TALIVIO_TELEMETRY_ENABLED', true),
    'ingest_timeout' => env('TALIVIO_INGEST_TIMEOUT', 5),

    // Hub'dan gelen GDPR silme çağrısının HMAC anahtarı.
    //
    // Boşsa `ingest_token`'a geri düşülür — bugüne kadarki davranış buydu ve
    // tüm ürünler öyle çalışıyor. AYRILMASININ SEBEBİ: ingest token'ı hub'da
    // artık hash'li saklanıyor (panelden okunamıyor) ve döndürülebilir; imza
    // anahtarı ona bağlı kalsaydı her token yenilemesi uçuştaki silme
    // çağrılarını doğrulanamaz hâle getirirdi. Hub bu değeri ürün başına
    // üretir; ürün tarafında tanımlanana kadar geri dönüş çalışmaya devam eder.
    'webhook_secret' => env('TALIVIO_WEBHOOK_SECRET'),

    // SDK, `talivio:heartbeat` komutunu 5 dakikada bir kendisi zamanlar; ürünün
    // routes/console.php'sine elle satır eklemek gerekmez. Ürün zamanlamayı
    // kendi devralmak isterse false yapıp kendi Schedule kaydını yazabilir.
    'heartbeat_schedule' => env('TALIVIO_HEARTBEAT_SCHEDULE', true),

    // The guard/model this app authenticates its end users with.
    'guard' => env('TALIVIO_GUARD', 'web'),
    'user_model' => env('TALIVIO_USER_MODEL', User::class),

    // Column on the user model storing the Talivio Accounts identity.
    'talivio_id_column' => 'talivio_id',

    // Where to send the user after a successful Talivio Accounts login.
    'login_redirect' => env('TALIVIO_LOGIN_REDIRECT', '/'),

    // What to do with the local user when their Talivio account is deleted
    // (GDPR cascade from the hub): 'delete' erases the local account too,
    // 'unlink' keeps it but severs the SSO link (use when local accounts own
    // business records that must survive).
    'deletion_behavior' => env('TALIVIO_DELETION_BEHAVIOR', 'delete'),

    /*
     | Shared mail theme (talivio/sdk registers it automatically — see
     | TalivioServiceProvider::registerMailTheme()). Every Talivio product
     | sends the SAME looking mail: Talivio logo on the left, the PRODUCT
     | name on the right, the body, then a legal footer with the Talivio
     | mark. A product only has to set `product_name`; everything else has
     | a working default.
     |
     | ⚠️ The defaults point at talivio.com-hosted assets on purpose: an
     | e-mail is read outside the product's own domain, so relative paths
     | and localhost URLs render as broken images.
     */
    'mail' => [
        // Header brand image (left cell). PNG/JPG — e-mail clients do not
        // reliably render SVG.
        'brand_logo' => env('TALIVIO_MAIL_LOGO', 'https://talivio.com/assets/images/icon-logo.png'),

        /*
         * Icon-only mark (no wordmark) — used in the legal footer row.
         *
         * ⚠️ NOT `icon.png`: that one is 2400×2400 and shrinks to 32px in
         * mail; leaving the resample to the client turned the mark into a
         * smudge. This file was produced for exactly this use (64px source,
         * 32px display).
         */
        'brand_logo_icon' => env('TALIVIO_MAIL_LOGO_ICON', 'https://talivio.com/assets/images/icon-mail-64.png'),

        // 28 → 40: the logo is the only image carrying identity in the mail;
        // smaller than this it disappears next to the body text.
        'brand_logo_height' => env('TALIVIO_MAIL_LOGO_HEIGHT', 40),
        'brand_color' => env('TALIVIO_MAIL_COLOR', '#0f172a'),

        /*
         * Product name shown in the mail (header alt text/fallback, and the
         * right-hand cell). Defaults to the app name; set it when APP_NAME
         * is not the customer-facing product name.
         */
        'product_name' => env('TALIVIO_MAIL_PRODUCT', env('APP_NAME', 'Talivio')),

        // Product's own site, linked from the header logo.
        'product_url' => env('TALIVIO_MAIL_PRODUCT_URL', env('APP_URL', 'https://talivio.com')),

        /*
         * The right-hand header cell. Empty = fall back to the product name
         * (2026-08-18 decision: a single "Support" label made mail from 30+
         * products indistinguishable). talivio.com's own corporate mail sets
         * this to "Support", since the product name is already in its logo.
         */
        'header_right' => env('TALIVIO_MAIL_HEADER_RIGHT'),

        /*
         * The answer to "why did I get this / who do I write to". Lives in
         * the header's mailto: link and in the footer.
         *
         * ⚠️ NOT info@: that is a group alias — unread, untracked, opens no
         * ticket. This must be a real support inbox or the customer's reply
         * disappears.
         */
        'support_email' => env('TALIVIO_MAIL_SUPPORT', 'support@talivio.com'),

        // Legal footer. In the EU the sender's legal identity and address
        // must appear in commercial e-mail.
        'legal' => [
            'company' => env('TALIVIO_MAIL_LEGAL_COMPANY', 'Talivio Technology OÜ'),
            'address' => env('TALIVIO_MAIL_LEGAL_ADDRESS', 'Ahtri tn 12, Kesklinna linnaosa, 15551 Tallinn, Estonia'),
            'vat_id' => env('TALIVIO_MAIL_LEGAL_VAT', 'EE102744206'),
        ],
    ],

    // Davranışsal insan doğrulaması (üçüncü partisiz "captcha").
    // Kullanım: forma `<x-talivio::human-check />`, validasyona
    // `'talivio_human' => [new \Talivio\Sdk\Human\Rules\Human]`.
    'human' => [
        'enabled' => env('TALIVIO_HUMAN_ENABLED', true),

        // İmzalı jetonun ömrü (sn) — formu açık bırakıp sonra dolduranlar
        // için geniş, tekrar-kullanım penceresini sınırlamak için sonlu.
        'token_ttl' => env('TALIVIO_HUMAN_TOKEN_TTL', 7200),

        // Form render'ından submit'e geçmesi gereken asgari süre (sn).
        // İmzalı iat'a göre sunucuda ölçülür; istemci beyanına güvenilmez.
        'min_seconds' => env('TALIVIO_HUMAN_MIN_SECONDS', 3),

        // Davranış sinyallerinden toplanması gereken asgari puan.
        // 3 = her gerçekçi insan akışının (klavye-only dahil) ulaşabildiği
        // taban; yükseltmeden önce log'lardaki skor dağılımını izleyin.
        'min_score' => env('TALIVIO_HUMAN_MIN_SCORE', 3),

        // Gölge mod: karar loglanır ama kayıt asla engellenmez. Canlı bir
        // üründe eşikleri gözlemleyerek açmak için önce bunu true yapın.
        'log_only' => env('TALIVIO_HUMAN_LOG_ONLY', false),

        // Test koşusunda kural varsayılan olarak atlanır (ürünlerin mevcut
        // kayıt testleri davranış payload'ı üretemez); kuralın kendisini
        // test eden senaryolar bunu true yapar.
        'enforce_in_tests' => false,
    ],

    /*
     | Alan adı / DNS / barındırma / posta altyapısı (Talivio\Sdk\Infra).
     |
     | Dört sözleşme (Registrar, Dns, Host, Mail), her biri için TEK
     | platform-geneli hesap: Namecheap (Openprovider yedek), Cloudflare,
     | Ploi, mailcow. Ürünlerde kalan: tenant modeli, faturalama, UI, iş
     | kuyruğu. TalivioServiceProvider::registerInfra() sözleşmeleri buradaki
     | sürücü adına göre bağlar; kimlik bilgileri eksikse çözümleme anında
     | NotConfiguredException fırlatır (HTTP çağrısının içinde 401 değil).
     |
     | ⚠️ IP allowlist tuzağı: Namecheap (ClientIp), Ploi (token başına),
     | mailcow ("Allow from") ve Cloudflare (token IP filtresi açıksa)
     | hepsi çağıran sunucunun çıkış IP'sini ister. Bütün ürünler aynı
     | sunucuda koştuğu için tek IP, tek allowlist girişi yeter — ama yeni
     | bir sunucu/lokal makine ilk çağrıda 403/1011150 alır; bu bir kapsam
     | (scope) sorunu değildir.
     |
     | Env adları Contentio'nun eski config/services.php'siyle uyumlu
     | tutuldu; Shops'un farklı yazdığı birkaç anahtar (NAMECHEAP_API_USER,
     | CLOUDFLARE_API_EMAIL) geri dönüş olarak okunuyor.
     */
    'infra' => [
        // Sözleşme → sürücü. Sürücü adı ürün tarafında kayda da yazılabilir
        // (ör. StoreDomain::registrar), o yüzden bir kez alan adı oluştuktan
        // sonra değişmemeli.
        'registrar' => env('DOMAIN_REGISTRAR', 'namecheap'),
        'dns' => env('DOMAIN_DNS', 'cloudflare'),
        'host' => env('DOMAIN_HOSTING', 'ploi'),
        'mail' => env('DOMAIN_MAIL', 'mailcow'),
        'product_mail' => env('DOMAIN_PRODUCT_MAIL', 'mailio'),

        // Kayıt operatöründen bağımsız satış politikası — her sürücü aynı
        // marjı ve aynı TLD listesini uygular.
        'domains' => [
            // Bayi fiyatının üstüne Talivio marjı, tam yüzde. 0 = fiyat
            // olduğu gibi geçer. Arama anında uygulanır; müşterinin gördüğü,
            // ödediği ve yenilediği tek bir tutardır.
            'margin_percent' => (int) env('DOMAIN_MARGIN_PERCENT', 0),

            // Satılan TLD'ler (virgülle). Boş = kısıtlama yok. Bazı AB
            // ccTLD'leri yerel varlık ister; bu hesapta .ee ve .com.tr
            // reddediliyor (2026-08-16 canlı doğrulama).
            'supported_tlds' => array_values(array_filter(array_map('trim', explode(',', (string) env('DOMAIN_SUPPORTED_TLDS', ''))))),
        ],

        // Namecheap XML API. ApiUser = HESAP KULLANICI ADI; anahtar tek
        // başına yetmez. Sandbox AYRI bir hesaptır (kendi anahtarı). Canlı
        // hesap: sandbox=false; yarım yapılandırılmış bir ortamın gerçek
        // alan adı almaması için geliştirmede NAMECHEAP_SANDBOX=true yazın.
        'namecheap' => [
            'api_key' => env('NAMECHEAP_API_KEY'),
            'username' => env('NAMECHEAP_USERNAME', env('NAMECHEAP_API_USER')),
            // Genelde username ile aynı; yalnız alt hesaplarda farklı.
            'api_user' => env('NAMECHEAP_API_USER', env('NAMECHEAP_USERNAME')),
            'client_ip' => env('NAMECHEAP_CLIENT_IP'),
            'sandbox' => (bool) env('NAMECHEAP_SANDBOX', false),
        ],

        // Openprovider REST API — yedek kayıt operatörü (DOMAIN_REGISTRAR=openprovider).
        'openprovider' => [
            'username' => env('OPENPROVIDER_USERNAME'),
            'password' => env('OPENPROVIDER_PASSWORD'),
            'base_url' => env('OPENPROVIDER_BASE_URL', 'https://api.openprovider.eu/v1beta'),
        ],

        // Cloudflare — Talivio'nun kendi hesabı. Tercihen kapsamlı API
        // token'ı (Zone:Read, Zone:Edit, DNS:Edit; Bearer). Eski Global API
        // Key + e-posta da çalışır ama Ploi'nin DNS-01 doğrulaması yalnız
        // token'la olur. İkisi de varsa token kazanır.
        'cloudflare' => [
            'api_token' => env('CLOUDFLARE_API_TOKEN'),
            'api_key' => env('CLOUDFLARE_API_KEY'),
            'email' => env('CLOUDFLARE_EMAIL', env('CLOUDFLARE_API_EMAIL')),
            'account_id' => env('CLOUDFLARE_ACCOUNT_ID'),
            // Platformun kendi bölgesi (talivio.com) — ürünlerin kendi alt
            // alan adı kayıtları için; SDK istemcisi bunu kullanmaz.
            'zone_id' => env('CLOUDFLARE_ZONE_ID'),
            'base_url' => env('CLOUDFLARE_BASE_URL', 'https://api.cloudflare.com/client/v4'),
            // Kayıtları turuncu bulut yap. Varsayılan kapalı: Let's Encrypt
            // origin'e düz HTTP ile doğrular ve proxy, bölgenin SSL modunun
            // Full (strict) olmasını da ister.
            'proxied' => (bool) env('CLOUDFLARE_PROXIED', false),
        ],

        // Ploi — ürünün koştuğu sunucu ve site. site_id ops kullanımında
        // (site açma) gerekmez; ürünün alan adı bağlama akışında gerekir.
        'ploi' => [
            'api_token' => env('PLOI_API_TOKEN'),
            'server_id' => env('PLOI_SERVER_ID'),
            'site_id' => env('PLOI_SITE_ID'),
            // Müşteri alan adı siteye nasıl bağlanır: "tenant" (Ploi
            // multi-tenancy, tenant başına sertifika, DNS-01 mümkün — Shops)
            // veya "alias" (site alias listesi + site sertifikası —
            // Contentio). Ürün bir kez seçtikten sonra DEĞİŞTİRMEMELİ:
            // mevcut alan adları öteki mekanizmada görünmez.
            'attach_mode' => env('PLOI_ATTACH_MODE', 'tenant'),
            // Sunucunun kendi IP'si yerine kullanılacak adres (floating IP / LB).
            'server_ip' => env('PLOI_SERVER_IP'),
            // Ploi profilinde kayıtlı DNS sağlayıcı kimliği (DNS-01 tenant
            // sertifikaları). Yoksa kapsamlı Cloudflare token'ı Ploi'ye
            // satır içi verilir.
            'dns_credential_id' => env('PLOI_DNS_CREDENTIAL_ID'),
            'base_url' => env('PLOI_BASE_URL', 'https://ploi.io/api'),
        ],

        /*
         | Mailio'nun sunucudan-sunucuya posta ucu — BİR ÜRÜNÜN KULLANMASI
         | GEREKEN YER BURASI (Talivio\Sdk\Infra\Contracts\ProductMail).
         |
         | Aşağıdaki `mailcow` bloğu ham posta sunucusudur ve PAYLAŞILAN
         | örneğin tamamına erişir — hiçbir ürüne ait olmayan, elle
         | yönetilen müşteri alan adları dahil. Mailio ise her üründe
         | satılan posta paketlerinin kayıt sahibi (2026-09-05 kararı) ve
         | her çağrıyı çağıran ürünün kendi müşterileriyle sınırlıyor.
         |
         | Anahtar Mailio'da üretilir: `php artisan mailio:mail-key <urun>`.
         | Düz metin bir kez görünür; Mailio yalnız hash'ini saklar.
         */
        'mail_gateway' => [
            'base_url' => env('TALIVIO_MAIL_URL', 'https://mailio.talivio.com/api/v1/mail'),
            'key' => env('TALIVIO_MAIL_KEY'),
            'timeout' => env('TALIVIO_MAIL_TIMEOUT', 20),
        ],

        // mailcow yönetim API'si — tüm müşteri alan adlarını barındıran TEK
        // örnek. mx_host/spf_value, bölgesi bizde olan alan adlarına
        // MX/SPF/DKIM'in otomatik yazılması için.
        //
        // ⚠️ ÜRÜNLER BUNU DOĞRUDAN KULLANMAMALI; yukarıdaki mail_gateway'i
        // kullanın. Bu blok Mailio'nun kendisi ve ops araçları içindir.
        'mailcow' => [
            'url' => env('MAILCOW_URL'),
            'api_key' => env('MAILCOW_API_KEY'),
            'mx_host' => env('MAILCOW_MX_HOST'),
            'spf_value' => env('MAILCOW_SPF_VALUE'),
            // Yeni alan adlarının mailcow arayüzündeki etiketi.
            'description' => env('MAILCOW_DOMAIN_DESCRIPTION', env('APP_NAME', 'Talivio')),
        ],
    ],
];
