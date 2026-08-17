<?php

namespace Tests\Feature;

use App\Enums\PaymentStatus;
use App\Jobs\InitiatePaymentWithProviderJob;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class InitiatePaymentWithProviderJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_posts_the_payment_details_to_the_fake_provider(): void
    {
        Http::fake(['*/api/fake-provider/charge' => Http::response(['received' => true], 202)]);

        $payment = Payment::factory()->create([
            'order_id' => 'order-1',
            'amount' => 15000,
            'currency' => 'CZK',
        ]);

        (new InitiatePaymentWithProviderJob($payment->id))->handle();

        Http::assertSent(fn ($request) => $request->url() === url('/api/fake-provider/charge')
            && $request['payment_id'] === $payment->id
            && $request['order_id'] === 'order-1'
            && $request['amount'] === 15000
            && $request['currency'] === 'CZK');
    }

    public function test_it_does_nothing_if_the_payment_is_no_longer_pending(): void
    {
        Http::fake();

        // Simulates the webhook having already arrived and resolved the payment before
        // this job got a chance to run - the queue makes no ordering guarantee between
        // the two, so the job must not blindly re-initiate a charge.
        $payment = Payment::factory()->paid()->create();

        (new InitiatePaymentWithProviderJob($payment->id))->handle();

        Http::assertNothingSent();
    }

    public function test_it_does_not_throw_if_the_payment_no_longer_exists(): void
    {
        Http::fake();

        (new InitiatePaymentWithProviderJob('01ARZ3NDEKTSV4RRFFQ69G5FAV'))->handle();

        Http::assertNothingSent();
    }

    /**
     * The whole point of this job: a connection failure/timeout talking to the
     * provider must not bubble up as a job failure - the real outcome is still coming,
     * later, via the provider's own webhook call. See the job's class comment.
     */
    public function test_a_connection_failure_is_swallowed_not_thrown(): void
    {
        Http::fake(function () {
            throw new ConnectionException('simulated timeout');
        });

        $payment = Payment::factory()->create();

        (new InitiatePaymentWithProviderJob($payment->id))->handle();

        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
    }
}
