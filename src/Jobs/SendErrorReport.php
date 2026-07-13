<?php

namespace Talivio\Sdk\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendErrorReport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public array $error) {}

    /**
     * Best-effort delivery only — this job must never surface a failure to
     * the host app's own error tracker. Rethrowing here (even with retries)
     * gets reported to Sentry by the queue worker on every failed attempt,
     * which turns a single telemetry hiccup (hub down, bad ingest token)
     * into a storm of unrelated "error reporting the error report failed"
     * noise. One attempt, swallow anything that goes wrong.
     */
    public function handle(): void
    {
        try {
            Http::withToken(config('talivio.ingest_token'))
                ->timeout(5)
                ->post(rtrim(config('talivio.hub_url'), '/').'/api/ingest/errors', [
                    'errors' => [$this->error],
                ])
                ->throw();
        } catch (Throwable $e) {
            Log::channel(config('logging.default'))->debug('talivio/sdk: error report delivery failed', [
                'message' => $e->getMessage(),
            ]);
        }
    }
}
