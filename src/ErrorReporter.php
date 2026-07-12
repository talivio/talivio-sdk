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
                'exception_class' => get_class($e),
                'message' => substr($e->getMessage(), 0, 2000),
                'environment' => app()->environment(),
                'url' => $request?->fullUrl(),
                'method' => $request?->method(),
                'trace' => substr($e->getTraceAsString(), 0, 8000),
                'talivio_id' => $talivioId,
                'app_version' => config('app.version'),
                'occurred_at' => now()->toIso8601String(),
            ]);
        } catch (Throwable) {
            // Swallow — telemetry must be fail-safe.
        }
    }
}
