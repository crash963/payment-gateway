<?php

use App\Enums\PaymentStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            // Same ULID reasoning as merchants - see that migration's comment.
            $table->ulid('id')->primary();

            // No cascadeOnDelete(): merchants are deactivated (see `active` on
            // merchants), never hard-deleted, so a payment losing its merchant via
            // cascade should never happen in practice - and if it somehow did, silently
            // wiping payment history would be far worse than the delete failing loudly.
            $table->foreignUlid('merchant_id')->constrained('merchants');

            // The merchant's own order reference. Deliberately NOT unique: a merchant
            // may legitimately create several payment attempts for the same order (e.g.
            // retrying after a decline) - idempotency_key is what prevents *duplicate*
            // submissions, order_id is just for the merchant's own bookkeeping/lookups.
            $table->string('order_id');

            // Integer minor units, not float/decimal - see App\ValueObjects\Money.
            // No ->unsigned(): SQL Server's grammar has no UNSIGNED support (Laravel
            // silently drops the modifier on sqlsrv), so non-negativity is enforced two
            // other ways instead: the CHECK constraint below, and Money's constructor.
            $table->bigInteger('amount');
            $table->char('currency', 3);

            $table->string('status')->default(PaymentStatus::Pending->value);

            // Required on every create request (see REST API step) - one merchant's
            // idempotency key must not collide with another's, so the constraint is
            // scoped to (merchant_id, idempotency_key), not global. This is also what
            // Day 2 will lean on for concurrency-safe "create if not exists".
            $table->string('idempotency_key');

            // Both optional: not every integration needs a hosted redirect (return_url)
            // or a per-payment webhook override (callback_url) - callback_url can fall
            // back to the merchant's configured default webhook URL.
            $table->string('return_url')->nullable();
            $table->string('callback_url')->nullable();

            $table->timestamps();

            $table->unique(['merchant_id', 'idempotency_key']);
            $table->index(['merchant_id', 'order_id']);
        });

        // Belt-and-suspenders beyond the app-layer Money validation: even a raw SQL
        // insert/update that bypasses Eloquent entirely can't put a negative amount in
        // this table. Laravel's schema builder didn't get a fluent check() helper until
        // Laravel 11, so on 10 this needs a raw statement.
        DB::statement('ALTER TABLE payments ADD CONSTRAINT chk_payments_amount_nonnegative CHECK (amount >= 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
