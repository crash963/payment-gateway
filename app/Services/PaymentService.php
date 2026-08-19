<?php

namespace App\Services;

use App\Enums\PaymentEventType;
use App\Exceptions\IdempotencyKeyConflictException;
use App\Jobs\InitiatePaymentWithProviderJob;
use App\Models\Merchant;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Support\DetectsUniqueConstraintViolations;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * See storage/docs/06-create-payment-endpoint.md for the full reasoning. Short version:
 * idempotent creation needs TWO layers, not one -
 *
 *  1. A pre-check SELECT (the fast, common path: a client retrying after a timeout with
 *     the same Idempotency-Key just gets the same payment back, no wasted INSERT attempt).
 *  2. A try/catch around the INSERT for the case where a second request's pre-check also
 *     found nothing, because it ran before the first request's INSERT committed. Without
 *     this, "check then insert" is a textbook TOCTOU race - the payments.merchant_id+
 *     idempotency_key UNIQUE index (see that migration) is what makes this ever throw
 *     instead of silently creating a duplicate.
 *
 * Both paths end up calling resolveExisting() - so the pre-check path (which IS directly
 * testable in a single-process test suite) exercises the exact same recovery logic the
 * race-catch path depends on, even though truly concurrent requests aren't something a
 * synchronous PHPUnit run can trigger on demand.
 */
class PaymentService
{
    use DetectsUniqueConstraintViolations;

    /**
     * @param  array{idempotency_key: string, order_id: string, amount: int, currency: string, return_url: ?string, callback_url: ?string}  $data
     * @return array{payment: Payment, created: bool} created=false means this was an
     *                                                idempotent replay of an existing payment, not a new one
     *
     * @throws IdempotencyKeyConflictException if the key was already used with different parameters
     */
    public function create(Merchant $merchant, array $data): array
    {
        $existing = Payment::where('merchant_id', $merchant->id)
            ->where('idempotency_key', $data['idempotency_key'])
            ->first();

        if ($existing) {
            return ['payment' => $this->resolveExisting($existing, $data), 'created' => false];
        }

        try {
            $payment = DB::transaction(function () use ($merchant, $data) {
                $payment = Payment::createPending([
                    'merchant_id' => $merchant->id,
                    'order_id' => $data['order_id'],
                    'amount' => $data['amount'],
                    'currency' => $data['currency'],
                    'idempotency_key' => $data['idempotency_key'],
                    'return_url' => $data['return_url'] ?? null,
                    'callback_url' => $data['callback_url'] ?? null,
                ]);

                PaymentEvent::create([
                    'payment_id' => $payment->id,
                    'type' => PaymentEventType::PaymentCreated,
                    'metadata' => ['order_id' => $payment->order_id],
                ]);

                return $payment;
            });

            // Only for a genuine fresh insert - never on a replay (would double-charge
            // the provider for the same logical payment) and never from inside the
            // catch block's race-recovery branch below (that branch means someone
            // ELSE'S request created this payment and already triggered its own
            // provider call). ->afterCommit() defers the actual queue push until the
            // transaction above has committed - dispatching before that would let the
            // job run and query for a payment row that, from its point of view, might
            // not exist yet.
            InitiatePaymentWithProviderJob::dispatch($payment->id)->afterCommit();

            return ['payment' => $payment, 'created' => true];
        } catch (QueryException $e) {
            if (! $this->isUniqueConstraintViolation($e)) {
                throw $e;
            }

            // Lost the race: another request with the same (merchant_id, idempotency_key)
            // committed between our SELECT above and this INSERT. That's not our error to
            // report - fetch what the winner created and treat this call as a replay of it.
            $winner = Payment::where('merchant_id', $merchant->id)
                ->where('idempotency_key', $data['idempotency_key'])
                ->firstOrFail();

            return ['payment' => $this->resolveExisting($winner, $data), 'created' => false];
        }
    }

    /**
     * @param  array{order_id: string, amount: int, currency: string}  $data
     */
    private function resolveExisting(Payment $existing, array $data): Payment
    {
        // (int) cast on amount only: found in code review that a client sending
        // amount as a numeric STRING (e.g. a form-encoded body, or a JSON client that
        // doesn't distinguish number/string) passes the 'integer' validation rule
        // (which checks numeric shape, not PHP type) unchanged - $existing->amount is
        // always an int via the model cast, so the old strict `===` compared int to
        // string and misclassified a genuine replay as a conflict. order_id/currency
        // don't need this - both sides are always strings there, no type to reconcile.
        $sameRequest = $existing->order_id === $data['order_id']
            && $existing->amount === (int) $data['amount']
            && $existing->currency === $data['currency'];

        if (! $sameRequest) {
            throw new IdempotencyKeyConflictException($existing->idempotency_key);
        }

        return $existing;
    }
}
