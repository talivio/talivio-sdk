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
                ->post(rtrim($this->baseUrl, '/').'/'.ltrim($path, '/'), $payload);
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
}
