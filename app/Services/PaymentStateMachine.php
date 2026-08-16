<?php

namespace App\Services;

use App\Enums\PaymentStatus;
use App\Exceptions\InvalidStateTransitionException;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;

/**
 * Performs the EFFECT of a status change; App\Enums\PaymentStatus owns the RULES (which
 * transitions are even valid). Keeping the rule table on the enum means it's testable
 * with zero DB/framework setup; keeping the effect here means "what actually happens
 * when a payment changes status" has one single place to look, instead of being
 * scattered across every controller/job that might change a payment's status.
 */
class PaymentStateMachine
{
    /**
     * @throws InvalidStateTransitionException if $to isn't reachable from the payment's
     *                                         current status
     */
    public function transitionTo(Payment $payment, PaymentStatus $to): Payment
    {
        $from = $payment->status;

        if ($from === $to) {
            // Not a transition at all (e.g. a second partial refund that doesn't change
            // the overall status) - nothing to validate or persist.
            return $payment;
        }

        if (! $from->canTransitionTo($to)) {
            throw new InvalidStateTransitionException($from, $to);
        }

        // A single UPDATE doesn't strictly need a transaction on its own. This
        // boundary is established now because the next step (payment_events audit
        // trail) will insert a PaymentEvent row in the same place - and at that point
        // the update+insert MUST commit or roll back together, or we'd end up with a
        // status change nobody can see in the audit history. Establishing the boundary
        // here, before it's load-bearing, means it can't be forgotten later.
        DB::transaction(function () use ($payment, $to) {
            $payment->status = $to;
            $payment->save();
        });

        return $payment->refresh();
    }
}
