<?php

namespace App\Exceptions;

use App\Enums\PaymentStatus;
use DomainException;

/**
 * Thrown by PaymentStateMachine when asked to move a Payment to a status its current
 * status doesn't allow. A DomainException (not a generic Exception/RuntimeException)
 * because this represents a business-rule violation, not an infrastructure failure -
 * callers (e.g. a future API exception handler) can catch DomainException to map this
 * to a 409 Conflict / 422, distinctly from a 500.
 */
class InvalidStateTransitionException extends DomainException
{
    public function __construct(
        public readonly PaymentStatus $from,
        public readonly PaymentStatus $to,
    ) {
        parent::__construct(
            "Cannot transition payment from [{$from->value}] to [{$to->value}]."
        );
    }
}
