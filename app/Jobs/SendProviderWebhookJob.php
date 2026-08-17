<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;

/**
 * The fake provider's side of reporting a charge outcome back to PayFlow - simulates an
 * external system independently calling PayFlow's webhook endpoint, asynchronously and
 * decoupled from FakeProviderController's own synchronous response (see that
 * controller for why: the synchronous response is unreliable by design, this is the
 * channel that's actually trusted).
 *
 * $useInvalidSignature exists only to let FakeProviderController simulate the
 * INVALID_CALLBACK scenario (a webhook signed with the wrong secret, which
 * VerifyProviderWebhookSignature must reject) - never true for a real outcome.
 */
class SendProviderWebhookJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $paymentId,
        public readonly string $eventId,
        public readonly string $status,
        public readonly bool $useInvalidSignature = false,
    ) {}

    public function handle(): void
    {
        // Signed over this exact raw string, not a re-encoded array - the receiving
        // side must verify against the exact bytes it received, so we send precisely
        // what we signed rather than letting the HTTP client re-serialize an array
        // (which could reorder keys/whitespace and make our own signature not match
        // what we sent).
        $rawBody = json_encode([
            'event_id' => $this->eventId,
            'payment_id' => $this->paymentId,
            'status' => $this->status,
        ]);

        $secret = $this->useInvalidSignature
            ? 'deliberately-wrong-secret'
            : config('services.fake_provider.webhook_secret');

        $signature = hash_hmac('sha256', $rawBody, $secret);

        Http::withHeaders([
            'Content-Type' => 'application/json',
            'X-Provider-Signature' => $signature,
        ])->withBody($rawBody, 'application/json')
            ->post(url('/api/provider/webhook'));
    }
}
