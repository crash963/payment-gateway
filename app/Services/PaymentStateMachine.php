<?php

namespace App\Services;

use App\Enums\PaymentEventType;
use App\Enums\PaymentStatus;
use App\Exceptions\InvalidStateTransitionException;
use App\Models\Payment;
use App\Models\PaymentEvent;
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
     * @param  array<string, mixed>  $metadata  extra context to attach to the resulting
     *                                          PaymentEvent (e.g. a provider response id) -
     *                                          merged with the always-present from/to values
     *
     * @throws InvalidStateTransitionException if $to isn't reachable from the payment's
     *                                         current status
     */
    public function transitionTo(Payment $payment, PaymentStatus $to, array $metadata = []): Payment
    {
        $from = $payment->status;

        if ($from === $to) {
            // Not a transition at all (e.g. a second partial refund that doesn't change
            // the overall status) - nothing to validate or persist, and nothing new to
            // put in the audit log either.
            return $payment;
        }

        if (! $from->canTransitionTo($to)) {
            throw new InvalidStateTransitionException($from, $to);
        }

        // The status UPDATE and the PaymentEvent INSERT must commit or roll back
        // together - a status change with no corresponding audit row (or vice versa)
        // would make payment_events an unreliable history, which defeats its purpose.
        DB::transaction(function () use ($payment, $from, $to, $metadata) {
            $payment->status = $to;
            $payment->save();

            PaymentEvent::create([
                'payment_id' => $payment->id,
                'type' => PaymentEventType::forStatus($to),
                'metadata' => [...$metadata, 'from' => $from->value, 'to' => $to->value],
            ]);
        });

        return $payment->refresh();
    }
}
