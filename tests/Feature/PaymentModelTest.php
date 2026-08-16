<?php

namespace Tests\Feature;

use App\Enums\PaymentStatus;
use App\Models\Merchant;
use App\Models\Payment;
use App\ValueObjects\Money;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PaymentModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_money_accessor_combines_amount_and_currency(): void
    {
        $payment = Payment::factory()->create(['amount' => 259900, 'currency' => 'CZK']);

        $this->assertTrue($payment->money()->equals(new Money(259900, 'CZK')));
    }

    public function test_status_is_cast_to_the_backed_enum(): void
    {
        $payment = Payment::factory()->create();

        $this->assertInstanceOf(PaymentStatus::class, $payment->status);
        $this->assertSame(PaymentStatus::Pending, $payment->status);
    }

    public function test_mass_assignment_ignores_status(): void
    {
        // Simulates a careless Payment::create($request->validated()) - status must
        // stay at whatever the DB default is, never settable directly from input.
        $payment = Payment::factory()->make();

        $payment->fill(['status' => PaymentStatus::Paid]);

        $this->assertSame(PaymentStatus::Pending, $payment->status);
    }

    public function test_idempotency_key_must_be_unique_per_merchant(): void
    {
        $merchant = Merchant::factory()->create();

        Payment::factory()->for($merchant)->create(['idempotency_key' => 'dup-key']);

        $this->expectException(QueryException::class);

        Payment::factory()->for($merchant)->create(['idempotency_key' => 'dup-key']);
    }

    public function test_the_same_idempotency_key_is_fine_across_different_merchants(): void
    {
        Payment::factory()->create(['idempotency_key' => 'shared-key']);
        $second = Payment::factory()->create(['idempotency_key' => 'shared-key']);

        $this->assertNotNull($second->id);
    }

    public function test_database_check_constraint_rejects_a_negative_amount(): void
    {
        $merchant = Merchant::factory()->create();

        // Deliberately bypassing Eloquent/Money entirely - this proves the protection
        // is a real DB-level CHECK constraint, not just app-layer validation that a raw
        // SQL insert (or a future bug in Money) could sidestep.
        $this->expectException(QueryException::class);

        DB::table('payments')->insert([
            'id' => (string) Str::ulid(),
            'merchant_id' => $merchant->id,
            'order_id' => 'order-negative',
            'amount' => -100,
            'currency' => 'CZK',
            'status' => PaymentStatus::Pending->value,
            'idempotency_key' => 'negative-amount-test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
