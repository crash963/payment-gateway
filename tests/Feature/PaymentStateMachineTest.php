<?php

namespace Tests\Feature;

use App\Enums\PaymentEventType;
use App\Enums\PaymentStatus;
use App\Exceptions\InvalidStateTransitionException;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Services\PaymentStateMachine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentStateMachineTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_valid_transition_persists_the_new_status(): void
    {
        $payment = Payment::factory()->create(); // Pending by default

        (new PaymentStateMachine)->transitionTo($payment, PaymentStatus::Paid);

        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
    }

    public function test_a_valid_transition_writes_a_payment_event(): void
    {
        $payment = Payment::factory()->create();

        (new PaymentStateMachine)->transitionTo($payment, PaymentStatus::Paid, ['note' => 'provider SUCCESS']);

        $event = PaymentEvent::where('payment_id', $payment->id)->sole();

        $this->assertSame(PaymentEventType::PaymentPaid, $event->type);
        $this->assertSame([
            'note' => 'provider SUCCESS',
            'from' => 'pending',
            'to' => 'paid',
        ], $event->metadata);
    }

    public function test_an_invalid_transition_throws_and_does_not_persist(): void
    {
        // Refunded is terminal - nothing may move it anywhere else.
        $payment = Payment::factory()->refunded()->create();

        try {
            (new PaymentStateMachine)->transitionTo($payment, PaymentStatus::Paid);
            $this->fail('Expected InvalidStateTransitionException was not thrown.');
        } catch (InvalidStateTransitionException $e) {
            $this->assertSame(PaymentStatus::Refunded, $e->from);
            $this->assertSame(PaymentStatus::Paid, $e->to);
        }

        $this->assertSame(PaymentStatus::Refunded, $payment->fresh()->status);
    }

    public function test_transitioning_to_the_same_status_is_a_no_op_not_an_error(): void
    {
        // Models the "second partial refund" case: status doesn't change, and this
        // must NOT throw InvalidStateTransitionException just because Paid->Paid isn't
        // a listed edge.
        $payment = Payment::factory()->partiallyRefunded()->create();

        $result = (new PaymentStateMachine)->transitionTo($payment, PaymentStatus::PartiallyRefunded);

        $this->assertSame(PaymentStatus::PartiallyRefunded, $result->status);
    }

    public function test_a_no_op_transition_does_not_write_a_payment_event(): void
    {
        $payment = Payment::factory()->partiallyRefunded()->create();

        (new PaymentStateMachine)->transitionTo($payment, PaymentStatus::PartiallyRefunded);

        $this->assertSame(0, PaymentEvent::where('payment_id', $payment->id)->count());
    }
}
