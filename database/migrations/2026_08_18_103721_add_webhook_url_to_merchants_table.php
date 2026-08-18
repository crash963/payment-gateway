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
        Schema::table('merchants', function (Blueprint $table) {
            // Nullable: a merchant may not have configured one yet (or may only ever
            // poll GET /payments/{id}) - delivery is simply skipped when null, not an
            // error. payment.callback_url (set per-payment) overrides this when present.
            $table->string('webhook_url')->nullable()->after('webhook_secret');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('merchants', function (Blueprint $table) {
            $table->dropColumn('webhook_url');
        });
    }
};
