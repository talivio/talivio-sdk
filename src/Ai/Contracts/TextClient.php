<?php

declare(strict_types=1);

namespace Talivio\Sdk\Ai\Contracts;

/**
 * Ürünlerin metin/JSON üretimi için kullandığı ORTAK sözleşme.
 *
 * İmza, envanterde bulunan `GeminiClient` kopyalarından çıkarıldı: en az on
 * projede aynı dört metot vardı (`enabled`, `model`, `generateText`,
 * `generateJson`) ve gövdeleri %95 aynıydı. Sözleşmeyi SDK'ya almak, göç
 * sırasında hem eski hem yeni yolun aynı tipte olmasını sağlıyor.
 *
 * ⚠️ HİÇBİR UYGULAMA İSTİSNA FIRLATMAZ. Hata hâlinde `null` dönülür; çağıran
 * taraf deterministik bir yedeğe düşer. Bu, kopyalanan istemcilerin ortak
 * davranışıydı ve göçte korunması şart: bir destek bileti AI arızasında
 * beklemeye alınmalı, hata sayfası göstermemeli.
 */
interface TextClient
{
    public function enabled(): bool;

    /** Kayda geçen model/tier adı. */
    public function model(): string;

    /** @param array{temperature?: float, max_tokens?: int} $opts */
    public function generateText(string $prompt, ?string $system = null, array $opts = []): ?string;

    /**
     * @param  array{temperature?: float, max_tokens?: int}  $opts
     * @return array<string, mixed>|null
     */
    public function generateJson(string $prompt, ?string $system = null, array $opts = []): ?array;
}
