<?php

namespace App\Jobs;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * PayFlow's side of "initiate a charge with the provider" - dispatched right after a
 * Payment is created (see PaymentService::create()), runs on the queue so a slow/timing-
 * out provider never blocks the merchant's POST /api/payments response.
 *
 * Deliberately does NOT act on this call's response, success or failure. That's the
 * whole point of the timeout scenario this exists to demonstrate: a 2xx here doesn't
 * guarantee the provider's result is final, and a timeout doesn't mean it failed - the
 * provider may have processed it anyway and the response just didn't make it back. The
 * only thing that's ever allowed to move the payment's status is the provider's own
 * webhook call to POST /api/provider/webhook (see ProviderWebhookController), because
 * that's the only message we can be sure represents the provider's actual final say.
 */
class InitiatePaymentWithProviderJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly string $paymentId) {}

    public function handle(): void
    {
        $payment = Payment::find($this->paymentId);

        // Gone, or already resolved by a webhook that arrived before this job even ran
        // (the queue makes no ordering guarantee between this job and a fast webhook) -
        // either way, there's nothing left to initiate.
        if (! $payment || $payment->status !== PaymentStatus::Pending) {
            return;
        }

        try {
            Http::timeout(2)->post(url('/api/fake-provider/charge'), [
                'payment_id' => $payment->id,
                'order_id' => $payment->order_id,
                'amount' => $payment->amount,
                'currency' => $payment->currency,
            ]);
        } catch (ConnectionException $e) {
            Log::info('Fake provider charge call did not complete; awaiting the provider webhook for the real outcome.', [
                'payment_id' => $payment->id,
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
