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
        Schema::create('payment_events', function (Blueprint $table) {
            $table->ulid('id')->primary();

            // cascadeOnDelete() here, unlike payments.merchant_id: an event row has no
            // meaning without its payment (merchants, by contrast, are deactivated, not
            // deleted, so payments never lose their merchant in practice).
            $table->foreignUlid('payment_id')->constrained('payments')->cascadeOnDelete();

            $table->string('type');

            // Flexible payload (e.g. from/to status, provider response snippet, HTTP
            // status of a webhook attempt once that exists). SQL Server has no native
            // JSON column type - this is NVARCHAR(MAX) under the hood with JSON_VALUE/
            // OPENJSON support, not indexable the way Postgres jsonb is. Fine here since
            // we never query *into* metadata, only read it back for a given event.
            $table->json('metadata')->nullable();

            // No updated_at: an audit log entry is never modified after it's written.
            // See PaymentEvent model for the app-level enforcement of that.
            $table->timestamp('created_at')->useCurrent();

            $table->index(['payment_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_events');
    }
};
