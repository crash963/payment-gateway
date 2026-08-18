<?php

namespace Tests\Feature;

use App\Enums\PaymentEventType;
use App\Enums\PaymentStatus;
use App\Exceptions\IdempotencyKeyConflictException;
use App\Exceptions\RefundExceedsRemainingAmountException;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\Refund;
use App\Services\RefundService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RefundServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_partial_refund_moves_the_payment_to_partially_refunded(): void
    {
        $payment = Payment::factory()->paid()->create(['amount' => 10000]);

        $result = (new RefundService)->create($payment, ['idempotency_key' => 'r-1', 'amount' => 3000]);

        $this->assertTrue($result['created']);
        $this->assertSame(3000, $result['refund']->amount);
        $this->assertSame(PaymentStatus::PartiallyRefunded, $payment->fresh()->status);
    }

    public function test_a_refund_covering_the_full_amount_moves_the_payment_to_refunded(): void
    {
        $payment = Payment::factory()->paid()->create(['amount' => 10000]);

        (new RefundService)->create($payment, ['idempotency_key' => 'r-1', 'amount' => 10000]);

        $this->assertSame(PaymentStatus::Refunded, $payment->fresh()->status);
    }

    public function test_sequential_partial_refunds_that_sum_to_the_full_amount_end_refunded(): void
    {
        $payment = Payment::factory()->paid()->create(['amount' => 10000]);
        $service = new RefundService;

        $service->create($payment, ['idempotency_key' => 'r-1', 'amount' => 3000]);
        $this->assertSame(PaymentStatus::PartiallyRefunded, $payment->fresh()->status);

        $service->create($payment, ['idempotency_key' => 'r-2', 'amount' => 7000]);
        $this->assertSame(PaymentStatus::Refunded, $payment->fresh()->status);
    }

    public function test_a_refund_exceeding_the_remaining_amount_is_rejected(): void
    {
        $payment = Payment::factory()->paid()->create(['amount' => 10000]);
        $service = new RefundService;
        $service->create($payment, ['idempotency_key' => 'r-1', 'amount' => 7000]);

        try {
            $service->create($payment, ['idempotency_key' => 'r-2', 'amount' => 5000]);
            $this->fail('Expected RefundExceedsRemainingAmountException was not thrown.');
        } catch (RefundExceedsRemainingAmountException $e) {
            $this->assertSame(5000, $e->requestedAmount);
            $this->assertSame(3000, $e->remainingAmount);
        }

        // Nothing persisted from the rejected attempt - the transaction rolled back.
        $this->assertSame(1, Refund::where('payment_id', $payment->id)->count());
        $this->assertSame(PaymentStatus::PartiallyRefunded, $payment->fresh()->status);
    }

    public function test_it_writes_a_refund_created_event(): void
    {
        $payment = Payment::factory()->paid()->create(['amount' => 10000]);

        $result = (new RefundService)->create($payment, ['idempotency_key' => 'r-1', 'amount' => 3000]);

        $event = PaymentEvent::where('payment_id', $payment->id)
            ->where('type', PaymentEventType::RefundCreated)
            ->sole();

        $this->assertSame($result['refund']->id, $event->metadata['refund_id']);
    }

    public function test_repeating_the_same_refund_request_replays_the_original(): void
    {
        $payment = Payment::factory()->paid()->create(['amount' => 10000]);
        $service = new RefundService;

        $first = $service->create($payment, ['idempotency_key' => 'r-1', 'amount' => 3000]);
        $second = $service->create($payment, ['idempotency_key' => 'r-1', 'amount' => 3000]);

        $this->assertFalse($second['created']);
        $this->assertTrue($first['refund']->is($second['refund']));
        $this->assertSame(1, Refund::where('payment_id', $payment->id)->count());
    }

    public function test_reusing_the_key_with_a_different_amount_conflicts(): void
    {
        $payment = Payment::factory()->paid()->create(['amount' => 10000]);
        $service = new RefundService;
        $service->create($payment, ['idempotency_key' => 'r-1', 'amount' => 3000]);

        $this->expectException(IdempotencyKeyConflictException::class);

        $service->create($payment, ['idempotency_key' => 'r-1', 'amount' => 4000]);
    }
}
