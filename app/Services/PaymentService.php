<?php

namespace App\Services;

use App\Enums\PaymentEventType;
use App\Exceptions\IdempotencyKeyConflictException;
use App\Models\Merchant;
use App\Models\Payment;
use App\Models\PaymentEvent;
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
     * SQLSTATE 23000 is the ANSI-standard "integrity constraint violation" code, returned
     * identically by SQL Server, MySQL and Postgres via PDO - portable, unlike checking a
     * driver-specific numeric error code. This table has exactly one UNIQUE constraint
     * besides its ULID primary key (merchant_id, idempotency_key), so any 23000 here is
     * safe to treat as that race. If a second UNIQUE constraint is ever added to
     * `payments`, this stops being precise and would need to inspect the constraint name.
     */
    private function isUniqueConstraintViolation(QueryException $e): bool
    {
        return $e->getCode() === '23000';
    }

    /**
     * @param  array{order_id: string, amount: int, currency: string}  $data
     */
    private function resolveExisting(Payment $existing, array $data): Payment
    {
        $sameRequest = $existing->order_id === $data['order_id']
            && $existing->amount === $data['amount']
            && $existing->currency === $data['currency'];

        if (! $sameRequest) {
            throw new IdempotencyKeyConflictException($existing->idempotency_key);
        }

        return $existing;
    }
}
