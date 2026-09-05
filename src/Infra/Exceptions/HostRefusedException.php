<?php

namespace Talivio\Sdk\Infra\Exceptions;

use RuntimeException;

/**
 * The vendor answered and said NO — a business refusal, not an outage:
 * "password does not meet policy", "object already exists", "quota
 * exceeded", "domain not found".
 *
 * ⚠️ WHY THIS IS A SEPARATE TYPE: callers have to tell a refusal apart
 * from a failure to reach the vendor at all, because the two need
 * opposite handling. A refusal carries a reason the CUSTOMER should see
 * and retrying will never help; an outage deserves "try again in a
 * moment" and no detail. Both used to arrive as plain RuntimeException,
 * so a product either showed raw transport errors to customers or
 * replaced real refusal reasons with a misleading "server unreachable" —
 * Mailio hit exactly the second case.
 *
 * Anything NOT of this type from an Infra client means the vendor could
 * not be reached or answered unusably (connection failure, HTTP 5xx,
 * unparseable body).
 */
class HostRefusedException extends RuntimeException
{
    /**
     * @param  string  $reason  the vendor's own wording, safe to show a customer
     */
    public function __construct(string $message, public readonly string $reason = '')
    {
        parent::__construct($message);
    }

    public static function withReason(string $service, string $reason): self
    {
        return new self("{$service} refused the request: {$reason}", $reason);
    }

    /** The vendor's wording alone, for a message shown to a customer. */
    public function reason(): string
    {
        return $this->reason !== '' ? $this->reason : $this->getMessage();
    }
}
