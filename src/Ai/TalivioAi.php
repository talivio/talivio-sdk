<?php

declare(strict_types=1);

namespace Talivio\Sdk\Ai;

use Illuminate\Support\Facades\Log;
use Talivio\Sdk\Ai\Contracts\DegradationStrategy;
use Talivio\Sdk\Ai\Contracts\Transport;

/**
 * İstemci projelerin tek giriş noktası (ADR-03).
 *
 * Varlık sebebi envanterde ölçüldü: aynı `GeminiClient`/`GeminiService` en az 10
 * projeye kopyalanmıştı. Ağ geçidi kurulup istemci paketi yazılmazsa aynı
 * kopyalama bu kez "gateway istemcisi" adıyla tekrarlanır.
 *
 * ⚠️ HİÇBİR METOT İSTİSNA FIRLATMAZ (ADR-17). Ağ geçidi tek kapı; arızası
 * istemci uygulamayı çökertmemeli. Her yol `AiResult` döner.
 */
final class TalivioAi
{
    public function __construct(
        private readonly Transport $transport,
        private readonly CircuitBreaker $breaker,
        private readonly DegradationStrategy $degradation,
        private readonly int $retries = 2,
        private readonly bool $logUsage = true,
    ) {}

    /**
     * @param  list<array{role: string, content: mixed}>  $messages
     * @param  array<string, mixed>  $options  tier, tools, temperature, max_tokens,
     *                                         response_format, end_user_ref, plan_tier
     */
    public function chat(array $messages, array $options = []): AiResult
    {
        $payload = ['messages' => $messages, ...$this->normalizeOptions($options)];

        return $this->call('chat/completions', $payload, 'text');
    }

    /**
     * Yapılandırılmış çıktı yardımcısı.
     *
     * Ağ geçidi şema desteklemeyen sağlayıcılarda köprüye geçiyor (0.RT.11), ama
     * bu bir GARANTİ değil: dönen metin yine de geçersiz JSON olabilir. Bu yüzden
     * `AiResult::json()` null dönebilir ve çağıran taraf bunu kontrol etmek
     * zorunda — sessizce boş dizi dönmek, hatayı veri katmanına taşımak olurdu.
     *
     * @param  array<string, mixed>  $schema
     */
    public function chatJson(array $messages, array $schema, array $options = []): AiResult
    {
        return $this->chat($messages, [
            ...$options,
            'response_format' => ['type' => 'json_schema', 'json_schema' => $schema],
        ]);
    }

    /**
     * @param  string|list<string>  $input
     */
    public function embed(string|array $input, array $options = []): AiResult
    {
        return $this->call('embeddings', ['input' => $input, ...$this->normalizeOptions($options)], 'embedding');
    }

    /** @param array<string, mixed> $payload */
    private function call(string $path, array $payload, string $capability): AiResult
    {
        if ($this->breaker->isOpen()) {
            // Arızalı geçide her istekte gitmek, gecikmeyi kullanıcıya ödetir.
            return $this->degrade($capability, $payload, 'kısa devre açık');
        }

        $lastStatus = null;

        for ($attempt = 0; $attempt <= $this->retries; $attempt++) {
            $response = $this->transport->post($path, $payload);
            $lastStatus = $response['status'];

            if ($lastStatus >= 200 && $lastStatus < 300) {
                $this->breaker->recordSuccess();

                return $this->toResult($response['body'], $path);
            }

            /*
             * 4xx YENİDEN DENENMEZ (429 hariç): kapsam hatası, geçersiz gövde ya
             * da iptal edilmiş anahtar tekrar denemekle düzelmez. Denemek yalnızca
             * gecikme ekler ve ağ geçidinin oran sınırını besler.
             */
            if ($lastStatus >= 400 && $lastStatus < 500 && $lastStatus !== 429) {
                // Kalıcı hata devreyi AÇMAZ: sorun geçitte değil bizim
                // isteğimizde. Devreyi açmak, düzeltilebilir bir hata yüzünden
                // çalışan geçidi de kapatmak olurdu.
                return $this->degrade($capability, $payload, 'kalıcı hata: '.$lastStatus.' '.($response['body']['error']['code'] ?? ''));
            }

            $this->breaker->recordFailure();

            if ($attempt < $this->retries) {
                // Üstel geri çekilme: 200ms, 400ms. Sabit bekleme, geçidin
                // toparlanmasına şans vermeden üst üste vurur.
                usleep((int) (200_000 * (2 ** $attempt)));
            }
        }

        return $this->degrade($capability, $payload, 'geçit erişilemez: '.$lastStatus);
    }

    /** @param array<string, mixed> $body */
    private function toResult(array $body, string $path): AiResult
    {
        // Embedding yanıtı farklı biçimde: vektörler `data` altında.
        if (isset($body['data'])) {
            return new AiResult(
                ok: true,
                text: '',
                usage: $body['usage']['talivio'] ?? [],
                // Vektörler metin alanına sığmaz; çağıran taraf `usage`den
                // boyutu görür ve vektörleri ham gövdeden okur.
                toolCalls: [],
            );
        }

        $message = $body['choices'][0]['message'] ?? [];
        $usage = $body['usage']['talivio'] ?? [];

        if ($this->logUsage && $usage !== []) {
            /*
             * `usage.talivio` istemci tarafında da loglanır: "bu yanıt neden
             * böyle geldi, hangi model çalıştı, ne tuttu" sorusunun cevabı
             * yalnızca ağ geçidinde kalırsa, istemci ekibi her sorusu için
             * geçide bakmak zorunda kalır.
             */
            Log::info('talivio-ai.kullanim', ['path' => $path] + $usage);
        }

        return AiResult::success(
            text: (string) ($message['content'] ?? ''),
            toolCalls: array_map(static fn (array $call): array => [
                'id' => (string) ($call['id'] ?? ''),
                'name' => (string) ($call['function']['name'] ?? ''),
                'arguments' => json_decode((string) ($call['function']['arguments'] ?? '{}'), true) ?? [],
            ], $message['tool_calls'] ?? []),
            usage: $usage,
        );
    }

    /** @param array<string, mixed> $payload */
    private function degrade(string $capability, array $payload, string $reason): AiResult
    {
        /*
         * ⚠️ BOZULMA HER ZAMAN `error` SEVİYESİNDE YAZILIR.
         *
         * Önceden yalnızca 4xx `Log::warning` ile yazılıyordu, erişilemezlik ve
         * kısa devre ise hiç yazılmıyordu. Üretimde `LOG_LEVEL=error` olduğu
         * için warning de yutuluyordu: sonuç, ağ geçidinin hiç çağrılamadığı
         * bir 404 arızasının altı üründe aylarca sessiz kalması oldu
         * (2026-07-27 onarımı). ADR-17 ürünün ÇÖKMEMESİNİ ister; SESSİZ
         * kalmasını değil — bozulma ürün için güvenli, operasyon için acildir.
         */
        Log::error('talivio-ai.bozulma', ['yetenek' => $capability, 'sebep' => $reason]);

        $fallback = $this->degradation->fallback($capability, $payload);

        if ($fallback !== null && isset($fallback['content'])) {
            return AiResult::fallback((string) $fallback['content'], $reason);
        }

        return AiResult::unavailable($reason);
    }

    /**
     * `tier` seçeneği `model` alanına SANAL ad olarak çevrilir (ADR-04): istemci
     * hiçbir zaman model ID'si yazmaz.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function normalizeOptions(array $options): array
    {
        $tier = $options['tier'] ?? null;
        unset($options['tier']);

        if (is_string($tier) && $tier !== '') {
            $options['model'] = 'talivio/'.$tier;
        }

        return $options;
    }
}
