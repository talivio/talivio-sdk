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

    public int $tries = 3;

    public array $backoff = [5, 30, 120];

    public function __construct(public array $error) {}

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

            throw $e;
        }
    }

    public function failed(): void
    {
        // Give up silently after retries — never impact the host app.
    }
}
