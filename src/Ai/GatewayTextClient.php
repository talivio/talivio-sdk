<?php

declare(strict_types=1);

namespace Talivio\Sdk\Ai;

use Talivio\Sdk\Ai\Contracts\TextClient;

/**
 * Talivio AI ağ geçidi üzerinden metin/JSON üretimi.
 *
 * Eski `GeminiClient` kopyalarıyla AYNI SÖZLEŞMEYİ taşır (asla fırlatmaz,
 * hatada `null`), böylece çağıran kodun mantığı göçte değişmiyor — yalnızca
 * hangi uygulamanın bağlandığı değişiyor.
 *
 * ⚠️ MODEL ADI GÖNDERİLMEZ (talivio-ai ADR-04). Eski istemciler
 * `services.gemini.model` okuyordu; burada yalnızca `tier` var. Model seçimi
 * ağ geçidinin kararı: hangi modelin ucuz/hızlı/yetenekli olduğu merkezde
 * ölçülüyor ve 18 projede ayrı ayrı güncellenmesi gerekmiyor.
 *
 * ⚠️ SAĞLAYICI ANAHTARI YOK (ADR-17). Ağ geçidi düşerse `TalivioAi`'nin bozulma
 * stratejisi devreye girer ve `null` dönülür; ürün AI'sız çalışmaya devam eder,
 * kendi başına sağlayıcıya GİTMEZ.
 */
final class GatewayTextClient implements TextClient
{
    public function __construct(private readonly TalivioAi $ai) {}

    public function enabled(): bool
    {
        return ! empty(config('talivio-ai.key'));
    }

    public function model(): string
    {
        return 'talivio/'.$this->tier();
    }

    public function generateText(string $prompt, ?string $system = null, array $opts = []): ?string
    {
        $result = $this->ai->chat($this->messages($prompt, $system), $this->options($opts));

        return $result->ok && trim($result->text) !== '' ? trim($result->text) : null;
    }

    public function generateJson(string $prompt, ?string $system = null, array $opts = []): ?array
    {
        /*
         * `json_object` yeterli: ağ geçidi şema desteklemeyen sağlayıcıda
         * köprüye geçiyor (talivio-ai 0.RT.11). Katı bir şema dayatmak, eski
         * istemcilerin serbest çıktı alanını gereksiz yere kısıtlardı.
         */
        $result = $this->ai->chat($this->messages($prompt, $system), [
            ...$this->options($opts),
            'response_format' => ['type' => 'json_object'],
        ]);

        if (! $result->ok) {
            return null;
        }

        /*
         * `json()` null dönebilir ve bu bilinçli: model yine geçersiz JSON
         * üretebilir. Sessizce boş dizi dönmek, hatayı çağıranın veri katmanına
         * taşımak olurdu — eski istemcilerin fence-temizleme mantığı da aynı
         * sebeple vardı.
         */
        return $result->json();
    }

    /** @return list<array{role: string, content: string}> */
    private function messages(string $prompt, ?string $system): array
    {
        $messages = [];

        if ($system !== null && $system !== '') {
            $messages[] = ['role' => 'system', 'content' => $system];
        }

        $messages[] = ['role' => 'user', 'content' => $prompt];

        return $messages;
    }

    /** @return array<string, mixed> */
    private function options(array $opts): array
    {
        return array_filter([
            'tier' => $opts['tier'] ?? $this->tier(),
            'temperature' => $opts['temperature'] ?? null,
            'max_tokens' => $opts['max_tokens'] ?? null,
            /*
             * talivio-ai ADR-34 — düşünme bütçesi.
             *
             * ⚠️ BU SATIR OLMADAN AĞ GEÇİDİNDEKİ ÖZELLİK İSTEMCİDEN
             * ERİŞİLEMİYORDU. Ölçüldü (2026-07-27, vatlio üretim):
             *   eski yol (gemini-2.5-flash) 2831 ms
             *   geçit `cheap`     (gemma, 0 düşünme)        4614 ms
             *   geçit `standard`  (deepseek-pro, 461 düşünme) 9775 ms
             * Yani gecikmenin ana kaynağı düşünme token'ları ve varsayılan
             * tier `standard` olduğu için ürünler en yavaş yolu alıyordu.
             *
             * ⚠️ `max_tokens` bunu SINIRLAMAZ: tavan yalnız görünür çıktıyı
             * keser, düşünme token'ları dışarıda kalır (hem faturaya hem
             * SÜREYE girer). Bu yüzden ayrı bir kola ihtiyaç vardı.
             */
            'reasoning_effort' => $opts['reasoning_effort'] ?? config('talivio-ai.reasoning_effort'),
        ], fn ($v): bool => $v !== null);
    }

    /**
     * Varsayılan kalite katmanı.
     *
     * ⚠️ Çağıran `opts['tier']` ile ezebilir: aynı üründe destek asistanı
     * (gecikmeye duyarlı) ile gece çalışan bir toplu iş aynı bandı istemek
     * zorunda değil.
     */
    private function tier(): string
    {
        return (string) config('talivio-ai.default_tier', 'standard');
    }
}
