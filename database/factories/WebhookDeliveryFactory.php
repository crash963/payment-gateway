<?php

namespace Database\Factories;

use App\Models\Merchant;
use App\Models\PaymentEvent;
use App\Models\WebhookDelivery;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WebhookDelivery>
 */
class WebhookDeliveryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'payment_event_id' => PaymentEvent::factory(),
            'merchant_id' => Merchant::factory(),
            'url' => fake()->url(),
            'attempt' => 1,
            'http_status' => 200,
            'response' => '{"received":true}',
            'successful' => true,
        ];
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'http_status' => 500,
            'response' => '{"error":"server error"}',
            'successful' => false,
        ]);
    }
}
