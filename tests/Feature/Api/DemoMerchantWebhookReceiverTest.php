<?php

namespace Tests\Feature\Api;

use App\Models\Merchant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class DemoMerchantWebhookReceiverTest extends TestCase
{
    use RefreshDatabase;

    private function post(array $payload, string $secret): TestResponse
    {
        $rawBody = json_encode($payload);
        $signature = hash_hmac('sha256', $rawBody, $secret);

        return $this->call('POST', '/api/demo/webhook-receiver', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X-PayFlow-Signature' => $signature,
        ], $rawBody);
    }

    public function test_it_accepts_a_correctly_signed_webhook(): void
    {
        $merchant = Merchant::factory()->create();

        $response = $this->post([
            'event_id' => 'evt-1',
            'type' => 'payment.paid',
            'merchant_id' => $merchant->id,
            'payment' => ['id' => 'pay-1', 'order_id' => 'order-1'],
        ], $merchant->webhook_secret);

        $response->assertOk();
    }

    public function test_the_webhookfail_order_id_prefix_simulates_a_server_error(): void
    {
        $merchant = Merchant::factory()->create();

        $response = $this->post([
            'event_id' => 'evt-1',
            'type' => 'payment.paid',
            'merchant_id' => $merchant->id,
            'payment' => ['id' => 'pay-1', 'order_id' => 'WEBHOOKFAIL-order-1'],
        ], $merchant->webhook_secret);

        $response->assertStatus(500);
    }
}
