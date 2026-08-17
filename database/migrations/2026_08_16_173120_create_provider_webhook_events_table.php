<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('provider_webhook_events', function (Blueprint $table) {
            $table->ulid('id')->primary();

            // The provider's own event identifier (opaque string, provider-generated -
            // not necessarily a ULID from the provider's side, even though our fake one
            // happens to use one). UNIQUE is what makes duplicate-delivery handling a DB
            // guarantee rather than an app-level "hope nobody races this" - same pattern
            // as payments.idempotency_key, applied to inbound webhooks instead of
            // inbound payment creation.
            $table->string('event_id')->unique();

            $table->foreignUlid('payment_id')->constrained('payments')->cascadeOnDelete();

            // The provider's reported outcome as received (not PaymentStatus - this is
            // an external system's vocabulary, e.g. "declined", kept as-received for
            // audit purposes even though ProviderScenario::outcome() is what actually
            // decides the PaymentStatus transition).
            $table->string('status');

            // Full raw webhook body, for debugging/audit - what did the provider
            // actually send us, verbatim.
            $table->json('payload')->nullable();

            // No updated_at: same append-only reasoning as payment_events.
            $table->timestamp('created_at')->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('provider_webhook_events');
    }
};
