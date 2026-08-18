<?php

namespace App\Services;

use App\Enums\PaymentEventType;
use App\Enums\PaymentStatus;
use App\Exceptions\IdempotencyKeyConflictException;
use App\Exceptions\RefundExceedsRemainingAmountException;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\Refund;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * The concurrency-critical operation in this whole project: two refund requests for
 * the same payment, arriving at the same time, must never be able to together refund
 * more than the payment's amount. See storage/docs for the full write-up of why this
 * needs a different technique than PaymentService's idempotency handling.
 *
 * Short version: PaymentService protects against "did this exact operation already
 * happen" - a UNIQUE constraint is a perfect fit, because either the row exists or it
 * doesn't. This protects against "is the running total still valid" - there's no
 * UNIQUE constraint that can express that, so instead every refund attempt takes a
 * `lockForUpdate()` on the PAYMENT row before computing the total. A second concurrent
 * attempt on the same payment simply blocks at the database level until the first
 * transaction commits or rolls back - by the time it gets the lock, it sees the first
 * refund's total for real, not a stale read from before it existed.
 */
class RefundService
{
    /**
     * @param  array{idempotency_key: string, amount: int}  $data
     * @return array{refund: Refund, created: bool}
     *
     * @throws RefundExceedsRemainingAmountException
     * @throws IdempotencyKeyConflictException
     */
    public function create(Payment $payment, array $data): array
    {
        $existing = Refund::where('payment_id', $payment->id)
            ->where('idempotency_key', $data['idempotency_key'])
            ->first();

        if ($existing) {
            return ['refund' => $this->resolveExisting($existing, $data), 'created' => false];
        }

        try {
            $refund = DB::transaction(function () use ($payment, $data) {
                // Blocks here if another transaction already holds this lock on the
                // same payment - that's the entire mechanism. Everything below only
                // runs once we're the sole holder of it.
                $lockedPayment = Payment::where('id', $payment->id)->lockForUpdate()->firstOrFail();

                $alreadyRefunded = (int) Refund::where('payment_id', $lockedPayment->id)->sum('amount');
                $remaining = $lockedPayment->amount - $alreadyRefunded;

                if ($data['amount'] > $remaining) {
                    throw new RefundExceedsRemainingAmountException($data['amount'], $remaining);
                }

                $refund = Refund::create([
                    'payment_id' => $lockedPayment->id,
                    'amount' => $data['amount'],
                    'idempotency_key' => $data['idempotency_key'],
                ]);

                PaymentEvent::create([
                    'payment_id' => $lockedPayment->id,
                    'type' => PaymentEventType::RefundCreated,
                    'metadata' => ['refund_id' => $refund->id, 'amount' => $refund->amount],
                ]);

                $isFullyRefunded = ($alreadyRefunded + $data['amount']) === $lockedPayment->amount;

                (new PaymentStateMachine)->transitionTo(
                    $lockedPayment,
                    $isFullyRefunded ? PaymentStatus::Refunded : PaymentStatus::PartiallyRefunded,
                    ['refund_id' => $refund->id]
                );

                return $refund;
            });

            return ['refund' => $refund, 'created' => true];
        } catch (QueryException $e) {
            if (! $this->isUniqueConstraintViolation($e)) {
                throw $e;
            }

            // Same race-recovery shape as PaymentService: someone else's request with
            // the same (payment_id, idempotency_key) committed between our pre-check
            // and our insert attempt.
            $winner = Refund::where('payment_id', $payment->id)
                ->where('idempotency_key', $data['idempotency_key'])
                ->firstOrFail();

            return ['refund' => $this->resolveExisting($winner, $data), 'created' => false];
        }
    }

    /**
     * SQLSTATE 23000 - see PaymentService for why this check is portable. refunds has
     * exactly one UNIQUE constraint (payment_id, idempotency_key).
     */
    private function isUniqueConstraintViolation(QueryException $e): bool
    {
        return $e->getCode() === '23000';
    }

    /**
     * @param  array{amount: int}  $data
     */
    private function resolveExisting(Refund $existing, array $data): Refund
    {
        if ($existing->amount !== $data['amount']) {
            throw new IdempotencyKeyConflictException($existing->idempotency_key);
        }

        return $existing;
    }
}
