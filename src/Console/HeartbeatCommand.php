<?php

namespace Talivio\Sdk\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class HeartbeatCommand extends Command
{
    protected $signature = 'talivio:heartbeat';

    protected $description = 'Ping the Talivio Ops dashboard so this product shows as "alive".';

    public function handle(): int
    {
        if (! config('talivio.ingest_token')) {
            return self::SUCCESS;
        }

        try {
            Http::withToken(config('talivio.ingest_token'))
                ->timeout(5)
                ->post(rtrim(config('talivio.hub_url'), '/').'/api/ingest/heartbeat');
        } catch (\Throwable) {
            // Fail silently — a missed heartbeat just shows as "last seen" lag.
        }

        return self::SUCCESS;
    }
}
