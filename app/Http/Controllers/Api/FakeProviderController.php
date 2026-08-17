<?php

namespace App\Http\Controllers\Api;

use App\Enums\PaymentStatus;
use App\Enums\ProviderScenario;
use App\Http\Controllers\Controller;
use App\Jobs\SendProviderWebhookJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Stands in for a real external payment processor - a route in this same app, not a
 * separate service, purely so the whole simulation is runnable without extra
 * infrastructure. Reached over real HTTP (from InitiatePaymentWithProviderJob) rather
 * than a plain method call specifically so that call goes through a real client
 * timeout, not a faked one - see that job's class comment.
 *
 * Scenario is picked from the "magic" order_id prefix - see ProviderScenario. This
 * endpoint has no merchant-facing purpose and isn't meant to resemble a real
 * production provider integration (a real one wouldn't be unauthenticated, wouldn't be
 * driven by the payment's own order_id, etc.) - it exists purely to make the async/
 * timeout/duplicate-delivery problems demonstrable end-to-end locally.
 */
class FakeProviderController extends Controller
{
    public function charge(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'payment_id' => ['required', 'string'],
            'order_id' => ['required', 'string'],
        ]);

        $scenario = ProviderScenario::fromOrderId($validated['order_id']);
        $eventId = (string) Str::ulid();

        $this->notifyPayFlow($validated['payment_id'], $eventId, $scenario);

        // TIMEOUT: sleep past InitiatePaymentWithProviderJob's 2s client timeout, so
        // that caller gives up before this response ever arrives - the webhook above
        // was already dispatched to the queue before this line, independent of whether
        // anyone is still waiting for this HTTP response.
        //
        // SLOW_RESPONSE: sleep under that timeout - arrives, just late, not an error.
        //
        // Note: the local dev server (`php artisan serve`) is single-threaded, so this
        // sleep briefly blocks any OTHER request to the app too. Fine for a local demo;
        // a concurrent server (Octane, a real production server) wouldn't have this
        // limitation.
        match ($scenario) {
            ProviderScenario::Timeout => sleep((int) config('services.fake_provider.timeout_delay_seconds')),
            ProviderScenario::SlowResponse => sleep((int) config('services.fake_provider.slow_response_delay_seconds')),
            default => null,
        };

        return response()->json(['received' => true], 202);
    }

    private function notifyPayFlow(string $paymentId, string $eventId, ProviderScenario $scenario): void
    {
        // Provider's own wire vocabulary ('success'/'declined'), derived from
        // ProviderScenario::outcome() rather than checking `$scenario === Declined`
        // directly here too - one single place decides which scenarios represent a
        // failed charge, instead of two places that would need to be kept in sync.
        $providerStatus = $scenario->outcome() === PaymentStatus::Failed ? 'declined' : 'success';

        match ($scenario) {
            // Same event_id sent twice on purpose - this is what
            // ProviderWebhookController's idempotent handling must survive.
            ProviderScenario::DuplicateCallback => collect([1, 2])->each(
                fn () => SendProviderWebhookJob::dispatch($paymentId, $eventId, $providerStatus)
            ),
            ProviderScenario::InvalidCallback => SendProviderWebhookJob::dispatch(
                $paymentId, $eventId, $providerStatus, useInvalidSignature: true
            ),
            default => SendProviderWebhookJob::dispatch($paymentId, $eventId, $providerStatus),
        };
    }
}
