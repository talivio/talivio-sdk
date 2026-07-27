<?php

namespace Talivio\Sdk\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Talivio\Sdk\Ingest\IngestClient;

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
     *
     * Yutma davranışı korundu, GÖRÜNMEZLİK korunmadı: IngestClient
     * başarısızlığı `warning` seviyesinde loglar (eskiden `debug` idi ve
     * üretimdeki varsayılan LOG_LEVEL altında hiç görünmüyordu), ve
     * `acceptJson()` sayesinde hub'ın reddettiği rapor artık 302 yerine 422
     * dönüyor — yani reddedilme gerçekten başarısızlık olarak görülüyor.
     */
    public function handle(IngestClient $ingest): void
    {
        $ingest->send('errors', ['errors' => [$this->error]]);
    }
}
