<?php

namespace Database\Factories;

use App\Enums\PaymentEventType;
use App\Models\Payment;
use App\Models\PaymentEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentEvent>
 */
class PaymentEventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * No forceFill() override needed here (unlike Merchant/Payment): every column has
     * a legitimate reason to be directly settable by trusted internal code, there's
     * nothing here that mirrors the api_key_hash/status "must only change through one
     * blessed code path" situation.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'payment_id' => Payment::factory(),
            'type' => PaymentEventType::PaymentCreated,
            'metadata' => null,
        ];
    }
}
