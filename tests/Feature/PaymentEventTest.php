<?php

namespace Tests\Feature;

use App\Enums\PaymentEventType;
use App\Models\Payment;
use App\Models\PaymentEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class PaymentEventTest extends TestCase
{
    use RefreshDatabase;

    public function test_type_and_metadata_are_cast(): void
    {
        $event = PaymentEvent::factory()->create([
            'type' => PaymentEventType::PaymentPaid,
            'metadata' => ['from' => 'authorized', 'to' => 'paid'],
        ]);

        $fresh = $event->fresh();

        $this->assertSame(PaymentEventType::PaymentPaid, $fresh->type);
        $this->assertSame(['from' => 'authorized', 'to' => 'paid'], $fresh->metadata);
    }

    public function test_updating_an_existing_event_is_rejected(): void
    {
        $event = PaymentEvent::factory()->create();

        $this->expectException(LogicException::class);

        $event->update(['type' => PaymentEventType::PaymentFailed]);
    }

    public function test_deleting_an_event_directly_is_rejected(): void
    {
        $event = PaymentEvent::factory()->create();

        $this->expectException(LogicException::class);

        $event->delete();
    }

    public function test_deleting_the_parent_payment_cascades_to_its_events(): void
    {
        $payment = Payment::factory()->create();
        PaymentEvent::factory()->for($payment)->create();

        // Goes around PaymentEvent's own delete guard on purpose - a payment being
        // deleted (e.g. cleaning up test fixtures) is a DB-level FK cascade, not an
        // application call to PaymentEvent::delete().
        Payment::where('id', $payment->id)->delete();

        $this->assertSame(0, PaymentEvent::where('payment_id', $payment->id)->count());
    }
}
