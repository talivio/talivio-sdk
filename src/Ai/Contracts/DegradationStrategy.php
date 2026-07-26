<?php

declare(strict_types=1);

namespace Talivio\Sdk\Ai\Contracts;

/**
 * ADR-17 — ZARİF BOZULMA SÖZLEŞMESİ.
 *
 * Doğrudan sağlayıcı yolu kapatıldığı için ağ geçidi tek kapıdır; erişilemezse
 * istemci AI'sız çalışmaya DEVAM ETMEK zorundadır. Bu arayüz o zorunluluğu
 * derleme zamanına taşır: istemci paketini kuran her proje bir bozulma
 * davranışı seçmek durumundadır.
 *
 * ⚠️ Arayüzün istisna fırlatan bir varyantı BİLİNÇLİ OLARAK YOKTUR. Ağ geçidi
 * arızasını son kullanıcının ekranına taşımak, merkezîleşmenin bedelini
 * kullanıcıya ödetmek olurdu (bkz. RiVo'nun sürüş güvenliği uyarıları —
 * AI'sız da çalışmalı, `2.MIG.07` ön koşulu).
 */
interface DegradationStrategy
{
    /**
     * Ağ geçidi erişilemezken ne dönecek?
     *
     * @param  string  $capability  İstenen yetenek ('text', 'image', ...)
     * @param  array<string, mixed>  $request  Ağ geçidine gönderilecek olan gövde
     * @return array<string, mixed>|null Yedek yanıt; null = özellik sessizce kapalı
     */
    public function fallback(string $capability, array $request): ?array;
}
