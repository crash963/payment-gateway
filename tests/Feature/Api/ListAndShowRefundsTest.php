<?php

namespace Tests\Feature\Api;

use App\Models\Merchant;
use App\Models\Payment;
use App\Models\Refund;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListAndShowRefundsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_merchant_can_list_refunds_for_its_own_payment(): void
    {
        $merchant = Merchant::factory()->withApiKey('test-key')->create();
        $payment = Payment::factory()->for($merchant)->paid()->create();
        Refund::factory()->for($payment)->count(2)->create();

        $response = $this->getJson("/api/payments/{$payment->id}/refunds", [
            'Authorization' => 'Bearer test-key',
        ]);

        $response->assertOk();
        $this->assertCount(2, $response->json('data'));
    }

    public function test_listing_refunds_for_another_merchants_payment_is_404(): void
    {
        $owner = Merchant::factory()->create();
        $requester = Merchant::factory()->withApiKey('test-key')->create();
        $payment = Payment::factory()->for($owner)->paid()->create();
        Refund::factory()->for($payment)->create();

        $response = $this->getJson("/api/payments/{$payment->id}/refunds", [
            'Authorization' => 'Bearer test-key',
        ]);

        $response->assertStatus(404);
    }

    public function test_a_merchant_can_view_its_own_refund_directly(): void
    {
        $merchant = Merchant::factory()->withApiKey('test-key')->create();
        $payment = Payment::factory()->for($merchant)->paid()->create();
        $refund = Refund::factory()->for($payment)->create();

        $response = $this->getJson("/api/refunds/{$refund->id}", [
            'Authorization' => 'Bearer test-key',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.id', $refund->id);
    }

    public function test_viewing_another_merchants_refund_directly_is_404(): void
    {
        $owner = Merchant::factory()->create();
        $requester = Merchant::factory()->withApiKey('test-key')->create();
        $payment = Payment::factory()->for($owner)->paid()->create();
        $refund = Refund::factory()->for($payment)->create();

        $response = $this->getJson("/api/refunds/{$refund->id}", [
            'Authorization' => 'Bearer test-key',
        ]);

        $response->assertStatus(404);
    }
}
