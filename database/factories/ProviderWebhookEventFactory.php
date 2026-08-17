<?php

namespace Database\Factories;

use App\Models\Payment;
use App\Models\ProviderWebhookEvent;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ProviderWebhookEvent>
 */
class ProviderWebhookEventFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_id' => (string) Str::ulid(),
            'payment_id' => Payment::factory(),
            'status' => 'success',
            'payload' => null,
        ];
    }
}
