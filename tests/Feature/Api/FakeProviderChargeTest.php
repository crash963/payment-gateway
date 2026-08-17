<?php

namespace Tests\Feature\Api;

use App\Jobs\SendProviderWebhookJob;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * FAKE_PROVIDER_TIMEOUT_DELAY_SECONDS/SLOW_RESPONSE_DELAY_SECONDS are 0 in
 * .env.testing (see config/services.php) - these tests exercise the scenario-selection
 * and job-dispatch logic, not the real multi-second sleep, which is only meaningful
 * for a live manual test against a running queue worker.
 */
class FakeProviderChargeTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_normal_order_id_dispatches_a_success_webhook(): void
    {
        Queue::fake();
        $payment = Payment::factory()->create(['order_id' => 'order-1']);

        $this->postJson('/api/fake-provider/charge', [
            'payment_id' => $payment->id,
            'order_id' => 'order-1',
        ])->assertStatus(202);

        Queue::assertPushed(SendProviderWebhookJob::class, fn ($job) => $job->paymentId === $payment->id
            && $job->status === 'success'
            && $job->useInvalidSignature === false);
    }

    public function test_a_decline_order_id_dispatches_a_declined_webhook(): void
    {
        Queue::fake();
        $payment = Payment::factory()->create(['order_id' => 'DECLINE-order-2']);

        $this->postJson('/api/fake-provider/charge', [
            'payment_id' => $payment->id,
            'order_id' => 'DECLINE-order-2',
        ])->assertStatus(202);

        Queue::assertPushed(SendProviderWebhookJob::class, fn ($job) => $job->status === 'declined');
    }

    public function test_a_duplicate_order_id_dispatches_the_webhook_twice_with_the_same_event_id(): void
    {
        Queue::fake();
        $payment = Payment::factory()->create(['order_id' => 'DUPLICATE-order-3']);

        $this->postJson('/api/fake-provider/charge', [
            'payment_id' => $payment->id,
            'order_id' => 'DUPLICATE-order-3',
        ])->assertStatus(202);

        Queue::assertPushed(SendProviderWebhookJob::class, 2);

        $eventIds = Queue::pushed(SendProviderWebhookJob::class)->pluck('eventId')->unique();

        $this->assertCount(1, $eventIds, 'Both dispatched jobs must share the same event_id.');
    }

    public function test_an_invalid_order_id_dispatches_a_webhook_flagged_for_an_invalid_signature(): void
    {
        Queue::fake();
        $payment = Payment::factory()->create(['order_id' => 'INVALID-order-4']);

        $this->postJson('/api/fake-provider/charge', [
            'payment_id' => $payment->id,
            'order_id' => 'INVALID-order-4',
        ])->assertStatus(202);

        Queue::assertPushed(SendProviderWebhookJob::class, fn ($job) => $job->useInvalidSignature === true);
    }
}
