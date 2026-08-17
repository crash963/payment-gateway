<?php

namespace Tests\Feature\Api;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\ProviderWebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ProviderWebhookTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Signs exactly the way SendProviderWebhookJob does - see that class. Tests build
     * the raw body themselves (rather than letting postJson() serialize the array)
     * specifically so the signature is computed over the exact bytes sent, matching
     * how VerifyProviderWebhookSignature verifies it.
     */
    private function postSignedWebhook(array $payload, ?string $secret = null): TestResponse
    {
        $rawBody = json_encode($payload);
        $signature = hash_hmac('sha256', $rawBody, $secret ?? config('services.fake_provider.webhook_secret'));

        return $this->call('POST', '/api/provider/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X-Provider-Signature' => $signature,
        ], $rawBody);
    }

    public function test_a_valid_success_webhook_marks_the_payment_paid(): void
    {
        $payment = Payment::factory()->create();

        $response = $this->postSignedWebhook([
            'event_id' => (string) Str::ulid(),
            'payment_id' => $payment->id,
            'status' => 'success',
        ]);

        $response->assertOk();
        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
    }

    public function test_a_valid_declined_webhook_marks_the_payment_failed(): void
    {
        $payment = Payment::factory()->create();

        $this->postSignedWebhook([
            'event_id' => (string) Str::ulid(),
            'payment_id' => $payment->id,
            'status' => 'declined',
        ])->assertOk();

        $this->assertSame(PaymentStatus::Failed, $payment->fresh()->status);
    }

    public function test_it_writes_a_payment_event_via_the_state_machine(): void
    {
        $payment = Payment::factory()->create();

        $this->postSignedWebhook([
            'event_id' => (string) Str::ulid(),
            'payment_id' => $payment->id,
            'status' => 'success',
        ]);

        $this->assertSame(1, PaymentEvent::where('payment_id', $payment->id)->count());
    }

    public function test_an_invalid_signature_is_rejected_and_does_not_change_the_payment(): void
    {
        $payment = Payment::factory()->create();

        $response = $this->postSignedWebhook([
            'event_id' => (string) Str::ulid(),
            'payment_id' => $payment->id,
            'status' => 'success',
        ], secret: 'wrong-secret');

        $response->assertStatus(401);
        $response->assertJsonPath('error.code', 'invalid_signature');
        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
    }

    public function test_a_duplicate_event_id_is_processed_only_once(): void
    {
        $payment = Payment::factory()->create();
        $eventId = (string) Str::ulid();

        $first = $this->postSignedWebhook(['event_id' => $eventId, 'payment_id' => $payment->id, 'status' => 'success']);
        $second = $this->postSignedWebhook(['event_id' => $eventId, 'payment_id' => $payment->id, 'status' => 'success']);

        $first->assertOk();
        $second->assertOk(); // redelivery is a success response too, not an error
        $this->assertSame(1, ProviderWebhookEvent::where('event_id', $eventId)->count());
        $this->assertSame(1, PaymentEvent::where('payment_id', $payment->id)->count());
    }

    public function test_an_unknown_payment_id_is_404(): void
    {
        $response = $this->postSignedWebhook([
            'event_id' => (string) Str::ulid(),
            'payment_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV',
            'status' => 'success',
        ]);

        $response->assertStatus(404);
    }
}
