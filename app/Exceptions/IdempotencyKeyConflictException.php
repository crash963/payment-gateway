<?php

namespace App\Exceptions;

use DomainException;

/**
 * Thrown when a merchant reuses an Idempotency-Key with different request parameters
 * than the original call that key was used for (different amount, currency, or
 * order_id). Distinct from "key already used with the SAME parameters", which is the
 * normal idempotent-replay case and is NOT an error - it just returns the original
 * payment.
 */
class IdempotencyKeyConflictException extends DomainException
{
    public function __construct(public readonly string $idempotencyKey)
    {
        parent::__construct(
            "Idempotency-Key [{$idempotencyKey}] was already used with different request parameters."
        );
    }
}
