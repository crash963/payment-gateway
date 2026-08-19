<?php

namespace App\Http\Controllers\Api;

use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\ProviderWebhookEvent;
use App\Services\PaymentStateMachine;
use App\Support\DetectsUniqueConstraintViolations;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Receives the provider's report of a charge outcome - the only thing in the whole
 * fake-provider flow that's allowed to actually move a Payment's status (see
 * InitiatePaymentWithProviderJob's class comment for why the initiating call's own
 * response is never trusted for that).
 *
 * Idempotency here uses the exact same shape as PaymentService::create(): a UNIQUE
 * constraint (provider_webhook_events.event_id) is the actual guarantee, a
 * try/insert + catch-the-race pattern is how we turn "someone already processed this
 * event" from an error into a clean no-op. Same technique, second application - this
 * time protecting against duplicate/retried webhook deliveries instead of duplicate
 * payment creation.
 */
class ProviderWebhookController extends Controller
{
    use DetectsUniqueConstraintViolations;

    public function handle(Request $request, PaymentStateMachine $stateMachine): JsonResponse
    {
        $validated = $request->validate([
            'event_id' => ['required', 'string'],
            'payment_id' => ['required', 'string'],
            'status' => ['required', 'string', 'in:success,declined'],
        ]);

        $payment = Payment::find($validated['payment_id']);
        abort_unless($payment, 404);

        try {
            DB::transaction(function () use ($validated, $payment, $stateMachine) {
                ProviderWebhookEvent::create([
                    'event_id' => $validated['event_id'],
                    'payment_id' => $payment->id,
                    'status' => $validated['status'],
                    'payload' => $validated,
                ]);

                $target = $validated['status'] === 'declined' ? PaymentStatus::Failed : PaymentStatus::Paid;

                $stateMachine->transitionTo($payment, $target, [
                    'provider_event_id' => $validated['event_id'],
                ]);
            });
        } catch (QueryException $e) {
            if ($this->isUniqueConstraintViolation($e)) {
                // Redelivery of an event we've already recorded - a webhook receiver
                // MUST answer this the same way it answered the first delivery
                // (success), or the provider will just keep retrying forever thinking
                // every attempt failed. This is exactly the DUPLICATE_CALLBACK scenario.
                return response()->json(['status' => 'already_processed'], 200);
            }

            throw $e;
        }

        return response()->json(['status' => 'processed'], 200);
    }
}
