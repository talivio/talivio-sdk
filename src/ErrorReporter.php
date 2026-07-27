<?php

namespace Talivio\Sdk;

use Illuminate\Support\Facades\Auth;
use Talivio\Sdk\Jobs\SendErrorReport;
use Throwable;

/**
 * Hooked into the host app's exception handler by TalivioServiceProvider.
 * Never throws — a telemetry failure must never break the product it's
 * installed in.
 */
class ErrorReporter
{
    /**
     * Hub'ın `ErrorIngestController` doğrulamasındaki sınırlar. Alanların
     * TAMAMI burada kırpılır: eskiden yalnızca `message` ve `trace` kırpılıyor,
     * `url` ve `app_version` ham gönderiliyordu. Uzun bir sorgu dizesi taşıyan
     * tek bir istek raporu 422'ye düşürüyor, o da (acceptJson öncesi) sessizce
     * kayboluyordu. Sınırlar hub ile birebir eşleşmeli.
     */
    private const LIMITS = [
        'exception_class' => 255,
        'message' => 2000,
        'environment' => 50,
        'url' => 2048,
        'method' => 10,
        'trace' => 8000,
        'app_version' => 50,
    ];

    public function report(Throwable $e): void
    {
        if (! config('talivio.telemetry_enabled') || ! config('talivio.ingest_token')) {
            return;
        }

        try {
            $request = app('request');
            $talivioId = null;

            try {
                $user = Auth::guard(config('talivio.guard'))->user();
                $talivioId = $user?->{config('talivio.talivio_id_column')};
            } catch (Throwable) {
                // Auth not resolvable in this context (e.g. queue worker) — skip.
            }

            SendErrorReport::dispatch([
                'fingerprint' => sha1(get_class($e).'|'.$e->getFile().'|'.$e->getLine()),
                'exception_class' => $this->clip('exception_class', get_class($e)),
                'message' => $this->clip('message', $e->getMessage()),
                'environment' => $this->clip('environment', app()->environment()),
                'url' => $this->clip('url', $request?->fullUrl()),
                'method' => $this->clip('method', $request?->method()),
                'trace' => $this->clip('trace', $e->getTraceAsString()),
                'talivio_id' => $talivioId,
                'app_version' => $this->clip('app_version', config('app.version')),
                'occurred_at' => now()->toIso8601String(),
            ]);
        } catch (Throwable) {
            // Swallow — telemetry must be fail-safe.
        }
    }

    /**
     * `mb_substr` bilinçli: `substr` bayt sayar ve Türkçe bir karakterin
     * ortasından kesip geçersiz UTF-8 üretebilir. O durumda isteğin gövdesi
     * `json_encode` aşamasında patlar, istisna yutulur ve rapor hiç gitmez —
     * yani kırpmanın kendisi rapor kaybının sebebi olurdu.
     */
    private function clip(string $field, ?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return mb_substr($value, 0, self::LIMITS[$field]);
    }
}
