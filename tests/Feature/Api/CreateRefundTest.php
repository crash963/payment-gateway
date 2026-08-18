<?php

namespace Tests\Feature\Api;

use App\Models\Merchant;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateRefundTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_merchant_can_refund_its_own_payment(): void
    {
        $merchant = Merchant::factory()->withApiKey('test-key')->create();
        $payment = Payment::factory()->for($merchant)->paid()->create(['amount' => 10000]);

        $response = $this->postJson("/api/payments/{$payment->id}/refunds", ['amount' => 3000], [
            'Authorization' => 'Bearer test-key',
            'Idempotency-Key' => 'refund-1',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.amount', 3000);
        $response->assertHeader('Location');
    }

    public function test_refunding_more_than_the_remaining_amount_is_a_409(): void
    {
        $merchant = Merchant::factory()->withApiKey('test-key')->create();
        $payment = Payment::factory()->for($merchant)->paid()->create(['amount' => 10000]);
        $headers = ['Authorization' => 'Bearer test-key'];

        $this->postJson("/api/payments/{$payment->id}/refunds", ['amount' => 7000], [
            ...$headers, 'Idempotency-Key' => 'refund-1',
        ]);

        $response = $this->postJson("/api/payments/{$payment->id}/refunds", ['amount' => 5000], [
            ...$headers, 'Idempotency-Key' => 'refund-2',
        ]);

        $response->assertStatus(409);
        $response->assertJsonPath('error.code', 'refund_exceeds_remaining_amount');
    }

    public function test_refunding_another_merchants_payment_is_404_not_403(): void
    {
        $owner = Merchant::factory()->create();
        $requester = Merchant::factory()->withApiKey('test-key')->create();
        $payment = Payment::factory()->for($owner)->paid()->create(['amount' => 10000]);

        $response = $this->postJson("/api/payments/{$payment->id}/refunds", ['amount' => 1000], [
            'Authorization' => 'Bearer test-key',
            'Idempotency-Key' => 'refund-1',
        ]);

        $response->assertStatus(404);
    }

    public function test_repeating_the_same_refund_request_is_idempotent(): void
    {
        $merchant = Merchant::factory()->withApiKey('test-key')->create();
        $payment = Payment::factory()->for($merchant)->paid()->create(['amount' => 10000]);
        $headers = ['Authorization' => 'Bearer test-key', 'Idempotency-Key' => 'refund-1'];

        $first = $this->postJson("/api/payments/{$payment->id}/refunds", ['amount' => 3000], $headers);
        $second = $this->postJson("/api/payments/{$payment->id}/refunds", ['amount' => 3000], $headers);

        $first->assertCreated();
        $second->assertOk();
        $this->assertSame($first->json('data.id'), $second->json('data.id'));
    }

    public function test_missing_idempotency_key_is_a_validation_error(): void
    {
        $merchant = Merchant::factory()->withApiKey('test-key')->create();
        $payment = Payment::factory()->for($merchant)->paid()->create();

        $response = $this->postJson("/api/payments/{$payment->id}/refunds", ['amount' => 1000], [
            'Authorization' => 'Bearer test-key',
        ]);

        $response->assertStatus(422);
    }

    public function test_creating_a_refund_requires_authentication(): void
    {
        $payment = Payment::factory()->paid()->create();

        $response = $this->postJson("/api/payments/{$payment->id}/refunds", ['amount' => 1000], [
            'Idempotency-Key' => 'refund-1',
        ]);

        $response->assertStatus(401);
    }
}
