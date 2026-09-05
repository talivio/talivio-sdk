<?php

namespace Talivio\Sdk\Infra\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;

/**
 * The retry policy every Infra client shares for READS: connection
 * failures and 5xx are retried a couple of times, 4xx (auth, validation,
 * not-found) come straight back so callers can read them. The response
 * — never a RequestException — is returned either way, so each client
 * can turn it into a RuntimeException its callers understand.
 *
 * Charge-bearing or non-idempotent calls (registering a domain,
 * requesting a certificate) must NOT go through this: a resend after an
 * ambiguous failure could register and bill twice.
 */
trait RetriesTransientFailures
{
    protected function retrying(PendingRequest $request, int $times = 3, int $sleepMilliseconds = 250): PendingRequest
    {
        return $request->retry($times, $sleepMilliseconds, fn ($exception) => $exception instanceof ConnectionException
            || ($exception instanceof RequestException && $exception->response->serverError()), throw: false);
    }
}
