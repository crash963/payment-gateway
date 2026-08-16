<?php

namespace Database\Factories;

use App\Enums\PaymentStatus;
use App\Models\Merchant;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'merchant_id' => Merchant::factory(),
            'order_id' => 'order-'.fake()->unique()->numberBetween(1, 1_000_000),
            'amount' => fake()->numberBetween(100, 1_000_00), // 1.00 - 10,000.00 in minor units
            'currency' => 'CZK',
            'status' => PaymentStatus::Pending,
            'idempotency_key' => (string) Str::uuid(),
        ];
    }

    /**
     * Same reason as MerchantFactory::newModel(): `status` is deliberately excluded
     * from Payment::$fillable (it must only ever change through PaymentStateMachine),
     * so the default constructor-based fill() would silently drop it here too.
     */
    public function newModel(array $attributes = [])
    {
        return (new Payment)->forceFill($attributes);
    }

    public function authorized(): static
    {
        return $this->state(fn (array $attributes) => ['status' => PaymentStatus::Authorized]);
    }

    public function paid(): static
    {
        return $this->state(fn (array $attributes) => ['status' => PaymentStatus::Paid]);
    }

    public function partiallyRefunded(): static
    {
        return $this->state(fn (array $attributes) => ['status' => PaymentStatus::PartiallyRefunded]);
    }

    public function refunded(): static
    {
        return $this->state(fn (array $attributes) => ['status' => PaymentStatus::Refunded]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes) => ['status' => PaymentStatus::Failed]);
    }
}
