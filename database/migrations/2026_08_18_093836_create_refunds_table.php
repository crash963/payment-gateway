<?php

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
        Schema::create('refunds', function (Blueprint $table) {
            $table->ulid('id')->primary();

            // cascadeOnDelete() - same reasoning as payment_events: a refund without
            // its payment is meaningless, and payments are only ever hard-deleted in
            // test cleanup, never in real usage.
            $table->foreignUlid('payment_id')->constrained('payments')->cascadeOnDelete();

            // Minor units, no `currency` column - a refund is always in its payment's
            // currency, there's no cross-currency refund concept, so duplicating
            // currency here would just be a second value that could (bug-wise) drift
            // from the payment's.
            $table->bigInteger('amount');

            // Required per request, scoped per PAYMENT (not per merchant like
            // payments.idempotency_key) - two different payments legitimately
            // reusing the same key string is fine, they're different operations.
            $table->string('idempotency_key');

            // No updated_at, no `status` column: a refund here is synchronous and
            // complete the instant it's created (no fake-provider round trip for
            // refunds, unlike payments) - so there's nothing to update. If async refund
            // processing is added later, `status` would need to come back.
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['payment_id', 'idempotency_key']);
        });

        // Same belt-and-suspenders reasoning as payments.amount - app-layer validation
        // (RefundService/Money) is necessary but not sufficient on its own.
        DB::statement('ALTER TABLE refunds ADD CONSTRAINT chk_refunds_amount_positive CHECK (amount > 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('refunds');
    }
};
