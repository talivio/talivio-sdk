<?php

namespace Talivio\Sdk\Ingest;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Talivio\Sdk\Talivio;
use Throwable;

/**
 * `/api/ingest/*` çağrılarının TEK çıkış noktası.
 *
 * ⚠️ `acceptJson()` burada zorunlu ve bu sınıfın asıl var oluş sebebi:
 * başlık gönderilmezse hub'ın `ValidationException`'ı 422 JSON yerine 302
 * yönlendirmeye dönüşür (Laravel `expectsJson()`'a bakar), Guzzle yönlendirmeyi
 * izler, ana sayfadan 200 döner ve `->throw()` hiç tetiklenmez. Sonuç:
 * reddedilen her hata raporu ve destek talebi izsiz kaybolur, üstelik destek
 * formunda kullanıcıya "gönderildi" denir. Çağrılar tek tek yazıldığı sürece
 * bu başlığın birinde unutulması an meselesiydi — hepsi buradan geçiyor.
 *
 * Fail-safe sözleşmesi: telemetri hiçbir koşulda ürünü kırmaz. Ağ hatası,
 * yapılandırma eksikliği ve HTTP hatası yutulur; yalnızca loglanır.
 */
class IngestClient
{
    /**
     * Ingest token tanımlı mı? Token yoksa telemetri tamamen no-op'tur —
     * ürün yapılandırılmamış demektir.
     */
    public function configured(): bool
    {
        return (bool) config('talivio.ingest_token');
    }

    /**
     * Ham gönderim. Yapılandırma eksik veya ağ hatası varsa null döner.
     */
    public function post(string $path, array $payload = []): ?Response
    {
        if (! $this->configured()) {
            return null;
        }

        try {
            return Http::withToken(config('talivio.ingest_token'))
                ->acceptJson()
                ->withHeaders(['X-Talivio-Sdk-Version' => Talivio::VERSION])
                ->timeout((int) config('talivio.ingest_timeout', 5))
                ->post($this->url($path), $payload);
        } catch (Throwable $e) {
            $this->logFailure($path, $e->getMessage());

            return null;
        }
    }

    /**
     * Gönderir ve başarı durumunu döndürür; başarısızlığı loglar.
     *
     * Log seviyesi bilinçli olarak `warning`: eskiden `debug` idi ve üretimde
     * varsayılan `LOG_LEVEL` altında hiç görünmüyordu, yani düşen telemetrinin
     * hiçbir izi kalmıyordu. `error` seçilmedi çünkü ürünün kendi hata
     * izleyicisi (Sentry vb.) çoğu kurulumda `error` ve üstünü yakalar — hub
     * kapalıyken "hata raporunun raporlanması başarısız" fırtınası çıkardı.
     */
    public function send(string $path, array $payload = []): bool
    {
        $response = $this->post($path, $payload);

        if ($response === null) {
            return false;
        }

        if ($response->failed()) {
            $this->logFailure($path, 'HTTP '.$response->status().' — '.$response->body());

            return false;
        }

        return true;
    }

    private function url(string $path): string
    {
        return rtrim((string) config('talivio.hub_url'), '/').'/api/ingest/'.ltrim($path, '/');
    }

    private function logFailure(string $path, string $reason): void
    {
        try {
            Log::warning('talivio/sdk: ingest gönderimi başarısız', [
                'endpoint' => $path,
                'reason' => mb_substr($reason, 0, 500),
                'sdk_version' => Talivio::VERSION,
            ]);
        } catch (Throwable) {
            // Loglama bile başarısızsa yutulur — fail-safe sözleşmesi.
        }
    }
}
