<?php

namespace Tests\Feature\Api;

use App\Models\Merchant;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShowPaymentTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_merchant_can_view_its_own_payment(): void
    {
        $merchant = Merchant::factory()->withApiKey('test-key')->create();
        $payment = Payment::factory()->for($merchant)->create();

        $response = $this->getJson("/api/payments/{$payment->id}", [
            'Authorization' => 'Bearer test-key',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.id', $payment->id);
    }

    /**
     * The important assertion here is the status code, not just "denied" - a 403
     * would confirm the id belongs to someone, a 404 doesn't. See PaymentPolicy::view().
     */
    public function test_a_merchant_gets_404_not_403_for_another_merchants_payment(): void
    {
        $owner = Merchant::factory()->create();
        $requester = Merchant::factory()->withApiKey('test-key')->create();
        $payment = Payment::factory()->for($owner)->create();

        $response = $this->getJson("/api/payments/{$payment->id}", [
            'Authorization' => 'Bearer test-key',
        ]);

        $response->assertStatus(404);
        $response->assertJsonPath('error.code', 'not_found');
    }

    public function test_a_nonexistent_payment_id_is_404(): void
    {
        $merchant = Merchant::factory()->withApiKey('test-key')->create();

        $response = $this->getJson('/api/payments/01ARZ3NDEKTSV4RRFFQ69G5FAV', [
            'Authorization' => 'Bearer test-key',
        ]);

        $response->assertStatus(404);
    }

    public function test_a_malformed_id_is_404_not_500(): void
    {
        $merchant = Merchant::factory()->withApiKey('test-key')->create();

        $response = $this->getJson('/api/payments/not-a-ulid', [
            'Authorization' => 'Bearer test-key',
        ]);

        $response->assertStatus(404);
    }

    public function test_viewing_a_payment_requires_authentication(): void
    {
        $payment = Payment::factory()->create();

        $response = $this->getJson("/api/payments/{$payment->id}");

        $response->assertStatus(401);
    }
}
