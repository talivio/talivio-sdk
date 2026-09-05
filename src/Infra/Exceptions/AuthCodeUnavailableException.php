<?php

namespace Talivio\Sdk\Infra\Exceptions;

use RuntimeException;

/**
 * The registrar doesn't expose EPP/auth codes over its API at all — not an
 * outage, not a locked domain, just a capability it lacks (Namecheap issues
 * codes from its control panel / to the registrant's email only). Callers
 * distinguish this from a generic RuntimeException so they can tell the
 * customer how to actually get the code rather than reporting an error.
 */
class AuthCodeUnavailableException extends RuntimeException
{
    public static function forRegistrar(string $registrar): self
    {
        return new self(
            ucfirst($registrar)." doesn't hand out auth (EPP) codes over its API. Unlock the domain here, then contact Talivio support — the code will be sent to the registrant email address on file."
        );
    }
}
