<?php

namespace App\Enums;

/**
 * Backed enum + the state machine's transition RULES (not the effect of transitioning -
 * that lives in App\Services\PaymentStateMachine). Deliberately kept dependency-free
 * (no DB, no framework calls) so the transition graph itself is trivial to unit test.
 *
 * See storage/docs/03-payment-state-machine.md for the full diagram and reasoning
 * behind each edge.
 */
enum PaymentStatus: string
{
    case Pending = 'pending';
    case Authorized = 'authorized';
    case Paid = 'paid';
    case PartiallyRefunded = 'partially_refunded';
    case Refunded = 'refunded';
    case Failed = 'failed';

    /**
     * The states this one is allowed to move to directly.
     *
     * Note there's no Paid -> PartiallyRefunded -> PartiallyRefunded self-loop listed
     * here: a second partial refund doesn't change the status, so it's never treated as
     * a "transition" at all (see PaymentStateMachine - it only calls canTransitionTo()
     * when the computed target actually differs from the current status).
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Authorized, self::Paid, self::Failed],
            self::Authorized => [self::Paid, self::Failed],
            self::Paid => [self::PartiallyRefunded, self::Refunded],
            self::PartiallyRefunded => [self::Refunded],
            self::Refunded, self::Failed => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    /**
     * Refunded and Failed are terminal: once there, nothing can move the payment
     * elsewhere (a refund can't be un-refunded, a failed attempt can't retroactively
     * succeed - a retry creates a *new* Payment, it doesn't resurrect this one).
     */
    public function isTerminal(): bool
    {
        return $this->allowedTransitions() === [];
    }
}
