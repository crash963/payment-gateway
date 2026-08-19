<?php

namespace Tests\Feature\Api;

use App\Enums\PaymentEventType;
use App\Models\Merchant;
use App\Models\Payment;
use App\Models\PaymentEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentEventsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_merchant_can_list_its_own_payments_events_in_chronological_order(): void
    {
        $merchant = Merchant::factory()->withApiKey('test-key')->create();
        $payment = Payment::factory()->for($merchant)->create();

        $created = PaymentEvent::factory()->for($payment)->create(['type' => PaymentEventType::PaymentCreated]);
        $paid = PaymentEvent::factory()->for($payment)->create(['type' => PaymentEventType::PaymentPaid]);

        $response = $this->getJson("/api/payments/{$payment->id}/events", [
            'Authorization' => 'Bearer test-key',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.0.id', $created->id);
        $response->assertJsonPath('data.1.id', $paid->id);
        $response->assertJsonPath('data.1.type', 'payment_paid');
    }

    public function test_a_merchant_gets_404_not_403_for_another_merchants_payment_events(): void
    {
        $owner = Merchant::factory()->create();
        $requester = Merchant::factory()->withApiKey('test-key')->create();
        $payment = Payment::factory()->for($owner)->create();
        PaymentEvent::factory()->for($payment)->create();

        $response = $this->getJson("/api/payments/{$payment->id}/events", [
            'Authorization' => 'Bearer test-key',
        ]);

        $response->assertStatus(404);
    }

    public function test_listing_payment_events_requires_authentication(): void
    {
        $payment = Payment::factory()->create();

        $response = $this->getJson("/api/payments/{$payment->id}/events");

        $response->assertStatus(401);
    }
}
