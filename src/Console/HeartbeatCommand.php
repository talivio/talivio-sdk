<?php

namespace Talivio\Sdk\Console;

use Illuminate\Console\Command;
use Talivio\Sdk\Ingest\IngestClient;

class HeartbeatCommand extends Command
{
    protected $signature = 'talivio:heartbeat';

    protected $description = 'Ping the Talivio Ops dashboard so this product shows as "alive".';

    public function handle(IngestClient $ingest): int
    {
        /*
         * `telemetry_enabled` kontrolü eskiden burada YOKTU: bayrak kapalıyken
         * de ürün 5 dakikada bir hub'a gidiyordu. Hub zaten reddediyordu
         * (AuthenticateIngestToken aynı bayrağı Application satırında arar),
         * yani sonuç boşa giden istek ve "gönderiyorum ama alınmıyor" gibi
         * yanıltıcı bir durumdu. Artık iki taraf aynı bayrağa saygı duyuyor.
         */
        if (! config('talivio.telemetry_enabled') || ! $ingest->configured()) {
            return self::SUCCESS;
        }

        // Kaçan bir heartbeat yalnızca "son görülme" gecikmesi demektir; komut
        // asla başarısız dönmez ki scheduler çıktısı gürültüye boğulmasın.
        $ingest->send('heartbeat');

        return self::SUCCESS;
    }
}
