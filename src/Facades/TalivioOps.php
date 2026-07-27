<?php

namespace Talivio\Sdk\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static bool member(array $data)
 * @method static bool subscription(array $data)
 * @method static bool sale(array $data)
 * @method static bool signupApplication(array $data)
 *
 * @see \Talivio\Sdk\Ingest\TalivioOps
 */
class TalivioOps extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Talivio\Sdk\Ingest\TalivioOps::class;
    }
}
