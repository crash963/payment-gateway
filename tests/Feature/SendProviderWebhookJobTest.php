<?php

namespace Tests\Feature;

use App\Jobs\SendProviderWebhookJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SendProviderWebhookJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_sends_a_correctly_signed_webhook(): void
    {
        Http::fake();

        (new SendProviderWebhookJob('payment-1', 'event-1', 'success'))->handle();

        Http::assertSent(function ($request) {
            $expectedBody = json_encode([
                'event_id' => 'event-1',
                'payment_id' => 'payment-1',
                'status' => 'success',
            ]);
            $expectedSignature = hash_hmac('sha256', $expectedBody, config('services.fake_provider.webhook_secret'));

            return $request->url() === url('/api/provider/webhook')
                && $request->body() === $expectedBody
                && $request->header('X-Provider-Signature')[0] === $expectedSignature;
        });
    }

    public function test_the_invalid_signature_flag_signs_with_a_wrong_secret(): void
    {
        Http::fake();

        (new SendProviderWebhookJob('payment-1', 'event-1', 'success', useInvalidSignature: true))->handle();

        $realSecret = config('services.fake_provider.webhook_secret');

        Http::assertSent(function ($request) use ($realSecret) {
            $correctSignature = hash_hmac('sha256', $request->body(), $realSecret);

            // The whole point of this flag: the signature sent must NOT match what the
            // real secret would have produced, so VerifyProviderWebhookSignature rejects it.
            return $request->header('X-Provider-Signature')[0] !== $correctSignature;
        });
    }
}
