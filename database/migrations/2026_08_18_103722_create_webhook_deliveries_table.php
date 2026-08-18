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
        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->ulid('id')->primary();

            // One row per DELIVERY ATTEMPT, not per event - a retried event produces
            // multiple rows sharing the same payment_event_id with an incrementing
            // `attempt`, which is what gives us delivery HISTORY (per requirements),
            // not just a "last known state". cascadeOnDelete: same reasoning as every
            // other event-log table here - a delivery record is meaningless without
            // the payment_event it's delivering.
            $table->foreignUlid('payment_event_id')->constrained('payment_events')->cascadeOnDelete();

            // Denormalized from payment_event->payment->merchant_id - the spec's own
            // webhook_deliveries shape lists it directly, and it lets "all deliveries
            // for merchant X" be queried without joining through payments/payment_events.
            $table->foreignUlid('merchant_id')->constrained('merchants')->cascadeOnDelete();

            $table->string('url');
            $table->unsignedInteger('attempt');

            // Both nullable: a connection failure/timeout never gets an HTTP status or
            // body at all - that's a real, distinct outcome from "the merchant server
            // responded with an error", not the same as http_status=0.
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->text('response')->nullable();

            // Denormalized success/fail flag (derived from http_status being 2xx at
            // insert time) so querying "did this ever succeed" doesn't need to
            // reimplement the 2xx check in every query.
            $table->boolean('successful');

            $table->timestamp('sent_at')->useCurrent();

            $table->index(['payment_event_id', 'attempt']);
            $table->index(['merchant_id', 'sent_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
    }
};
