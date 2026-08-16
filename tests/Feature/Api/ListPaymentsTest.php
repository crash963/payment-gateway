<?php

namespace Tests\Feature\Api;

use App\Enums\PaymentStatus;
use App\Models\Merchant;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListPaymentsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_merchant_only_sees_its_own_payments(): void
    {
        $merchant = Merchant::factory()->withApiKey('test-key')->create();
        $other = Merchant::factory()->create();

        Payment::factory()->for($merchant)->count(2)->create();
        Payment::factory()->for($other)->count(3)->create();

        $response = $this->getJson('/api/payments', ['Authorization' => 'Bearer test-key']);

        $response->assertOk();
        $this->assertCount(2, $response->json('data'));
    }

    public function test_the_response_includes_pagination_metadata(): void
    {
        $merchant = Merchant::factory()->withApiKey('test-key')->create();
        Payment::factory()->for($merchant)->count(3)->create();

        $response = $this->getJson('/api/payments', ['Authorization' => 'Bearer test-key']);

        $response->assertOk();
        $response->assertJsonStructure(['data', 'links', 'meta']);
        $response->assertJsonPath('meta.total', 3);
    }

    public function test_filtering_by_status(): void
    {
        $merchant = Merchant::factory()->withApiKey('test-key')->create();
        Payment::factory()->for($merchant)->paid()->create();
        Payment::factory()->for($merchant)->create(); // pending

        $response = $this->getJson('/api/payments?status=paid', ['Authorization' => 'Bearer test-key']);

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $response->assertJsonPath('data.0.status', PaymentStatus::Paid->value);
    }

    public function test_an_invalid_status_filter_is_a_validation_error(): void
    {
        $merchant = Merchant::factory()->withApiKey('test-key')->create();

        $response = $this->getJson('/api/payments?status=not-a-real-status', [
            'Authorization' => 'Bearer test-key',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'validation_failed');
    }

    public function test_per_page_over_the_cap_is_a_validation_error(): void
    {
        $merchant = Merchant::factory()->withApiKey('test-key')->create();

        $response = $this->getJson('/api/payments?per_page=101', ['Authorization' => 'Bearer test-key']);

        $response->assertStatus(422);
    }

    public function test_listing_payments_requires_authentication(): void
    {
        $response = $this->getJson('/api/payments');

        $response->assertStatus(401);
    }
}
