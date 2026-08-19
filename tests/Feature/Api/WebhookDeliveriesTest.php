<?php

namespace Tests\Feature\Api;

use App\Models\Merchant;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\WebhookDelivery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebhookDeliveriesTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_merchant_can_list_deliveries_for_its_own_payment(): void
    {
        $merchant = Merchant::factory()->withApiKey('test-key')->create();
        $payment = Payment::factory()->for($merchant)->create();
        $event = PaymentEvent::factory()->for($payment)->create();

        $first = WebhookDelivery::factory()->for($event)->for($merchant)->create(['attempt' => 1]);
        $second = WebhookDelivery::factory()->for($event)->for($merchant)->failed()->create(['attempt' => 2]);

        $response = $this->getJson("/api/payments/{$payment->id}/webhook-deliveries", [
            'Authorization' => 'Bearer test-key',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.0.id', $first->id);
        $response->assertJsonPath('data.1.id', $second->id);
        $response->assertJsonPath('data.1.successful', false);
    }

    /**
     * Guards the exact join logic in WebhookDeliveryController - deliveries are
     * scoped through the PAYMENT's own events, not merely merchant_id, so a delivery
     * belonging to a different payment (even the same merchant's) must never leak in.
     */
    public function test_deliveries_for_a_different_payment_of_the_same_merchant_are_excluded(): void
    {
        $merchant = Merchant::factory()->withApiKey('test-key')->create();
        $payment = Payment::factory()->for($merchant)->create();
        $otherPayment = Payment::factory()->for($merchant)->create();

        $otherEvent = PaymentEvent::factory()->for($otherPayment)->create();
        WebhookDelivery::factory()->for($otherEvent)->for($merchant)->create();

        $response = $this->getJson("/api/payments/{$payment->id}/webhook-deliveries", [
            'Authorization' => 'Bearer test-key',
        ]);

        $response->assertOk();
        $this->assertCount(0, $response->json('data'));
    }

    public function test_a_merchant_gets_404_not_403_for_another_merchants_payment_deliveries(): void
    {
        $owner = Merchant::factory()->create();
        $requester = Merchant::factory()->withApiKey('test-key')->create();
        $payment = Payment::factory()->for($owner)->create();

        $response = $this->getJson("/api/payments/{$payment->id}/webhook-deliveries", [
            'Authorization' => 'Bearer test-key',
        ]);

        $response->assertStatus(404);
    }

    public function test_listing_webhook_deliveries_requires_authentication(): void
    {
        $payment = Payment::factory()->create();

        $response = $this->getJson("/api/payments/{$payment->id}/webhook-deliveries");

        $response->assertStatus(401);
    }
}
