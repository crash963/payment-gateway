<?php

namespace App\Jobs;

use App\Exceptions\WebhookDeliveryFailedException;
use App\Http\Resources\PaymentResource;
use App\Models\PaymentEvent;
use App\Models\WebhookDelivery;
use App\Services\UrlSafetyChecker;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Delivers one PaymentEvent to its merchant's configured webhook_url (or the payment's
 * callback_url override), signed with the merchant's webhook_secret. Dispatched from
 * PaymentStateMachine::transitionTo() for event types that have a
 * PaymentEventType::webhookEventName() (payment.paid etc.) - see that enum.
 *
 * Retries via Laravel's own queue retry machinery ($tries/backoff()), not manual retry
 * logic - a thrown exception here is what makes that happen. Every attempt (success or
 * failure) writes its own WebhookDelivery row before anything else happens, so the
 * delivery history exists even if this attempt then throws.
 */
class DeliverMerchantWebhookJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    public function __construct(public readonly string $paymentEventId) {}

    /**
     * Seconds to wait before each retry. 4 gaps for 5 tries: fails fast once, then
     * backs off - a merchant server having a brief blip recovers within the first
     * couple of retries; a genuinely broken endpoint gets a full 5 attempts spread
     * over ~20 minutes before landing in failed_jobs.
     */
    public function backoff(): array
    {
        return [10, 60, 300, 900];
    }

    public function handle(UrlSafetyChecker $urlSafety): void
    {
        $event = PaymentEvent::with('payment.merchant')->find($this->paymentEventId);

        // Gone (shouldn't happen - payment_events are never deleted except via
        // cascade from the payment itself) - nothing left to deliver.
        if (! $event) {
            return;
        }

        $webhookName = $event->type->webhookEventName();

        // Defensive: dispatch is already gated on webhookEventName() !== null in
        // PaymentStateMachine, so this should never actually be null here.
        if ($webhookName === null) {
            return;
        }

        $merchant = $event->payment->merchant;
        $url = $event->payment->callback_url ?? $merchant->webhook_url;

        // Nothing configured - not a failure, just nothing to do.
        if (! $url) {
            return;
        }

        $attempt = $this->attempts();

        if (! $urlSafety->isSafe($url)) {
            WebhookDelivery::create([
                'payment_event_id' => $event->id,
                'merchant_id' => $merchant->id,
                'url' => $url,
                'attempt' => $attempt,
                'http_status' => null,
                'response' => 'Refused: URL failed SSRF safety check.',
                'successful' => false,
            ]);

            // No retry - the URL isn't going to become safe on its own between now
            // and the next attempt, so retrying would just waste 4 more attempts.
            $this->fail('Webhook URL failed SSRF safety check.');

            return;
        }

        $payload = [
            'event_id' => $event->id,
            'type' => $webhookName,
            // Unlike PaymentResource (the public API response), merchant_id IS
            // included here - the receiving server needs it to know which of its
            // (potentially several) configured PayFlow accounts/secrets to verify
            // against. That's not an information leak the way it would be in the
            // public API - the merchant receiving this webhook already knows who they are.
            'merchant_id' => $merchant->id,
            'payment' => (new PaymentResource($event->payment))->resolve(),
            'occurred_at' => $event->created_at->toIso8601String(),
        ];

        // Signed over the exact raw string sent, not a re-encoded array - same
        // reasoning as SendProviderWebhookJob.
        $rawBody = json_encode($payload);
        $signature = hash_hmac('sha256', $rawBody, $merchant->webhook_secret);

        try {
            $response = Http::timeout((int) config('webhooks.delivery_timeout_seconds'))
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'X-PayFlow-Signature' => $signature,
                ])
                ->withBody($rawBody, 'application/json')
                ->post($url);

            WebhookDelivery::create([
                'payment_event_id' => $event->id,
                'merchant_id' => $merchant->id,
                'url' => $url,
                'attempt' => $attempt,
                'http_status' => $response->status(),
                'response' => Str::limit($response->body(), 1000),
                'successful' => $response->successful(),
            ]);

            if (! $response->successful()) {
                throw new WebhookDeliveryFailedException(
                    "Merchant webhook endpoint responded with HTTP {$response->status()}."
                );
            }
        } catch (ConnectionException $e) {
            WebhookDelivery::create([
                'payment_event_id' => $event->id,
                'merchant_id' => $merchant->id,
                'url' => $url,
                'attempt' => $attempt,
                'http_status' => null,
                'response' => Str::limit($e->getMessage(), 1000),
                'successful' => false,
            ]);

            throw $e;
        }
    }

    /**
     * Called once after all $tries are exhausted (job about to land in failed_jobs).
     * The WebhookDelivery rows already tell the full per-attempt story; this is just a
     * single clear log line marking that PayFlow gave up.
     */
    public function failed(\Throwable $exception): void
    {
        Log::warning('Merchant webhook delivery permanently failed after exhausting retries.', [
            'payment_event_id' => $this->paymentEventId,
            'exception' => $exception->getMessage(),
        ]);
    }
}
