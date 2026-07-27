<?php

declare(strict_types=1);

namespace Talivio\Sdk\Ai\Transports;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Talivio\Sdk\Ai\Contracts\Transport;

/**
 * Gerçek taşıma. ⚠️ Sağlayıcı anahtarı YOK, yalnızca ağ geçidi anahtarı (ADR-17).
 */
final class HttpTransport implements Transport
{
    public function __construct(
        private readonly HttpFactory $http,
        private readonly string $baseUrl,
        private readonly ?string $key,
        private readonly int $timeout,
        private readonly int $connectTimeout,
    ) {}

    public function post(string $path, array $payload): array
    {
        try {
            $response = $this->http
                ->timeout($this->timeout)
                ->connectTimeout($this->connectTimeout)
                ->withToken((string) $this->key)
                ->acceptJson()
                ->post($this->url($path), $payload);
        } catch (ConnectionException) {
            /*
             * Bağlantı hatası bir İSTİSNA olarak yukarı taşınmaz: ADR-17 gereği
             * istemci uygulama ağ geçidi yüzünden ÇÖKMEMELİ. 0 durumu "geçide
             * ulaşılamadı" demektir ve çağıran taraf bunu yeniden denenebilir
             * sayar.
             */
            return ['status' => 0, 'body' => []];
        }

        return ['status' => $response->status(), 'body' => (array) $response->json()];
    }

    /**
     * ⚠️ API SÜRÜM ÖNEKİNİ SDK KOYAR, ÜRÜNÜN `.env`'İ DEĞİL.
     *
     * Bu satır bir üretim hatasının onarımı (2026-07-27): `base_url` ürünlerde
     * doğal olarak `https://ai.talivio.com` yazıyordu, `chat/completions` de
     * öneksizdi — ikisi birleşince `/v1` hiç oluşmuyor ve ağ geçidi 404
     * dönüyordu. Hata GÖRÜNMÜYORDU çünkü `TalivioAi` 4xx'i bozulma yoluna
     * çevirip `null` döndürüyor (ADR-17: ürün AI yüzünden çökmez) ve teşhis
     * satırı `Log::warning`'e yazıldığı için `LOG_LEVEL=error` olan üretimde
     * yutuluyordu. Sonuç: altı üründe gölge koşu aylarca "geçit boş dönüyor"
     * ölçüyordu — oysa geçit hiç çağrılmamıştı.
     *
     * Sürüm önekini burada tutmanın sebebi: `base_url` altı ürünün `.env`'inde
     * ayrı ayrı yazılı ve hepsini aynı anda değiştiremiyoruz. Zaten `/v1` ile
     * biten bir taban da kabul ediliyor, yoksa `/v1/v1/...` üretirdik.
     */
    private function url(string $path): string
    {
        $base = rtrim($this->baseUrl, '/');

        if (! str_ends_with($base, '/v1')) {
            $base .= '/v1';
        }

        return $base.'/'.ltrim($path, '/');
    }
}
