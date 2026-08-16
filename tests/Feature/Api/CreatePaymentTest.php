<?php

namespace Tests\Feature\Api;

use App\Models\Merchant;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreatePaymentTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'order_id' => 'order-1',
            'amount' => 259900,
            'currency' => 'CZK',
        ], $overrides);
    }

    public function test_an_authenticated_merchant_can_create_a_payment(): void
    {
        $merchant = Merchant::factory()->withApiKey('test-key')->create();

        $response = $this->postJson('/api/payments', $this->payload(), [
            'Authorization' => 'Bearer test-key',
            'Idempotency-Key' => 'idem-1',
        ]);

        // Default Laravel JsonResource wrapping ({"data": {...}}) - kept deliberately
        // rather than disabled, since GET /api/payments (next step) will want `data`
        // alongside pagination `meta`/`links`, and it's one less thing to special-case
        // between a single-resource and a collection response.
        $response->assertCreated();
        $response->assertJsonPath('data.order_id', 'order-1');
        $response->assertJsonPath('data.amount', 259900);
        $response->assertJsonPath('data.currency', 'CZK');
        $response->assertJsonPath('data.status', 'pending');
        $response->assertHeader('Location');

        $this->assertDatabaseHas('payments', [
            'merchant_id' => $merchant->id,
            'order_id' => 'order-1',
        ]);
    }

    public function test_creating_a_payment_requires_authentication(): void
    {
        $response = $this->postJson('/api/payments', $this->payload(), [
            'Idempotency-Key' => 'idem-1',
        ]);

        $response->assertStatus(401);
        $response->assertJsonPath('error.code', 'unauthenticated');
    }

    public function test_a_merchant_cannot_spoof_another_merchants_id(): void
    {
        $merchant = Merchant::factory()->withApiKey('test-key')->create();
        $otherMerchant = Merchant::factory()->create();

        $this->postJson('/api/payments', $this->payload(['merchant_id' => $otherMerchant->id]), [
            'Authorization' => 'Bearer test-key',
            'Idempotency-Key' => 'idem-1',
        ]);

        $payment = Payment::sole();
        $this->assertSame($merchant->id, $payment->merchant_id);
    }

    public function test_missing_idempotency_key_header_is_a_validation_error(): void
    {
        $merchant = Merchant::factory()->withApiKey('test-key')->create();

        $response = $this->postJson('/api/payments', $this->payload(), [
            'Authorization' => 'Bearer test-key',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'validation_failed');
        $response->assertJsonPath('error.details.idempotency_key.0', 'The idempotency key field is required.');
    }

    public function test_invalid_amount_is_a_validation_error(): void
    {
        $merchant = Merchant::factory()->withApiKey('test-key')->create();

        $response = $this->postJson('/api/payments', $this->payload(['amount' => 0]), [
            'Authorization' => 'Bearer test-key',
            'Idempotency-Key' => 'idem-1',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'validation_failed');
        $this->assertArrayHasKey('amount', $response->json('error.details'));
    }

    public function test_repeating_the_same_request_is_idempotent(): void
    {
        $merchant = Merchant::factory()->withApiKey('test-key')->create();
        $headers = ['Authorization' => 'Bearer test-key', 'Idempotency-Key' => 'idem-1'];

        $first = $this->postJson('/api/payments', $this->payload(), $headers);
        $second = $this->postJson('/api/payments', $this->payload(), $headers);

        $first->assertCreated();
        $second->assertOk(); // 200, not 201 - this is a replay, not a new resource
        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, Payment::count());
    }

    public function test_reusing_the_idempotency_key_with_different_parameters_conflicts(): void
    {
        $merchant = Merchant::factory()->withApiKey('test-key')->create();
        $headers = ['Authorization' => 'Bearer test-key', 'Idempotency-Key' => 'idem-1'];

        $this->postJson('/api/payments', $this->payload(['amount' => 100]), $headers);
        $response = $this->postJson('/api/payments', $this->payload(['amount' => 200]), $headers);

        $response->assertStatus(409);
        $response->assertJsonPath('error.code', 'idempotency_key_conflict');
    }
}
