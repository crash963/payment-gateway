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
        Schema::create('merchants', function (Blueprint $table) {
            // ULID instead of auto-increment integer.
            //
            // Why: this table's primary key is exposed via the REST API (and will set the
            // pattern for Payment/Refund too). An auto-increment int leaks how many rows
            // exist and lets an attacker enumerate other tenants' records by guessing
            // neighbouring IDs (e.g. /api/payments/42, /43...). A random UUIDv4 avoids that,
            // but as a *clustered index* primary key it causes bad insert locality on SQL
            // Server (random inserts scatter across the B-tree -> page splits, fragmentation;
            // this is exactly why MS ships NEWSEQUENTIALID() as an alternative to NEWID()).
            // ULID is time-ordered (first 48 bits = ms timestamp, remaining 80 bits = random),
            // so inserts stay roughly append-only like an auto-increment int, while the ID is
            // still unguessable enough for a public identifier and sorts chronologically for
            // free (handy for keyset pagination). Trade-off: unlike UUIDv4, ULID does leak an
            // approximate creation timestamp - acceptable here, but worth knowing.
            $table->ulid('id')->primary();

            $table->string('name');

            // API key handling: we NEVER store the raw key, only a SHA-256 hash.
            //
            // Why SHA-256 (a *fast* hash) instead of bcrypt (a *slow* hash, Laravel's usual
            // Hash::make()): bcrypt embeds a random salt in its output, so two hashes of the
            // same input differ - there is no way to index it for a point lookup. The only way
            // to verify a bcrypt-hashed API key would be to loop over every merchant and call
            // Hash::check() until one matches, which is O(n) per request and deliberately slow
            // (bcrypt costs ~100ms+ by design). Slow hashing exists to raise the cost of
            // offline brute-forcing low-entropy, human-chosen secrets (passwords). Our API key
            // is a machine-generated ~256-bit random token, not human-chosen and not reused -
            // brute-forcing it is infeasible regardless of hash speed. So we use a fast,
            // deterministic hash instead, store it with a UNIQUE index, and look merchants up
            // with `WHERE api_key_hash = ?` in O(1). If the DB leaks, the attacker gets a hash
            // they cannot turn back into a usable key (this is the same pattern GitHub/Stripe
            // use for API tokens).
            $table->string('api_key_hash', 64)->unique();

            // Webhook signing secret: encrypted at rest, NOT hashed.
            //
            // Why encrypted, not hashed: unlike the API key (which we only ever need to
            // *verify*), this secret is used by PayFlow to *compute* an outgoing HMAC
            // signature when delivering webhooks to the merchant - so we must be able to read
            // the plaintext back. Laravel's `encrypted` cast (AES-256-CBC under APP_KEY) gives
            // us that while still not storing it in the clear. Caveat to be honest about: this
            // only protects the secret if APP_KEY doesn't leak alongside a DB dump - it's
            // defense in depth, not a guarantee.
            $table->text('webhook_secret');

            // Lets us revoke/disable a merchant without deleting their historical
            // payments/refunds (which we need to keep for the audit trail).
            $table->boolean('active')->default(true);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('merchants');
    }
};
