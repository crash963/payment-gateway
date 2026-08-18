<?php

namespace App\Exceptions;

use DomainException;

/**
 * Thrown when a refund would push sum(refunds) past the payment's amount. A
 * DomainException, same reasoning as InvalidStateTransitionException - a business-rule
 * violation, not an infrastructure failure.
 */
class RefundExceedsRemainingAmountException extends DomainException
{
    public function __construct(
        public readonly int $requestedAmount,
        public readonly int $remainingAmount,
    ) {
        parent::__construct(
            "Refund amount [{$requestedAmount}] exceeds the remaining refundable amount [{$remainingAmount}]."
        );
    }
}
