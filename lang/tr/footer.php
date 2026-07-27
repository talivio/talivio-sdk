<?php

/*
 * `<x-talivio::footer />` metinleri.
 *
 * NEDEN ÇEVİRİ DOSYASI: alt bilgi 20 küsur üründe basılıyor ve hepsi Türkçe
 * değil (a11yproof, canopyproof, privascan İngilizce arayüzlü). Bileşenin
 * içine sabit metin yazsaydık, İngilizce bir ürünün alt bilgisi Türkçe çıkardı.
 *
 * Ürün kendi metnini değiştirmek isterse:
 *   php artisan vendor:publish --tag=talivio-lang
 * ve `lang/vendor/talivio/{locale}/footer.php` dosyasını düzenler.
 */

return [
    'product' => 'Ürün',
    'legal' => 'Yasal',
    'contact' => 'İletişim',

    'privacy' => 'Gizlilik politikası',
    'terms' => 'Kullanım şartları',
    'impressum' => 'Künye',

    'follow' => 'Talivio’yu takip edin',
    'made_by' => 'Yapımcı',
    'rights_company' => '© :year Talivio Technology OÜ. Tüm hakları saklıdır.',

    'badge_security_title' => 'TCSR tarafından bağımsız denetlendi — canlı güvenlik durumunu görün',
    'badge_security_alt' => ':product için TCSR güvenlik notu',
    'badge_a11y_title' => 'A11yProof tarafından bağımsız denetlendi — canlı erişilebilirlik durumunu görün',
    'badge_a11y_alt' => ':product için A11yProof WCAG 2.2 AA durumu',
    'badge_privacy_title' => 'PrivaScan tarafından bağımsız denetlendi — canlı gizlilik durumunu görün',
    'badge_privacy_alt' => ':product için PrivaScan gizlilik durumu',
];
