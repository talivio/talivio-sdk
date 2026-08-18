<?php

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
    'user_model' => env('TALIVIO_USER_MODEL', \App\Models\User::class),

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
];
