<?php

namespace Talivio\Sdk\Infra\Exceptions;

use RuntimeException;

/**
 * A client was asked for but its credentials aren't in the environment.
 * Thrown at resolution time (TalivioServiceProvider::registerInfra()) so
 * a half-configured product fails on the first line that needs the
 * integration, with the env variable named — not somewhere inside an
 * HTTP call with a 401.
 */
class NotConfiguredException extends RuntimeException
{
    /**
     * @param  list<string>  $envKeys  the variables that are missing
     */
    public static function forService(string $service, array $envKeys): self
    {
        return new self(ucfirst($service).' is not configured — set '.implode(', ', $envKeys).' in the environment.');
    }
}
