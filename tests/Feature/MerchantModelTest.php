<?php

namespace Tests\Feature;

use App\Models\Merchant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Feature tests: anything that touches the database or needs the container
 * (factories use fake(), which is resolved from the app). RefreshDatabase runs
 * migrate:fresh once for the suite and rolls back each test in a transaction.
 *
 * Note: per project decision, this runs against the real local "PaymentGateway" SQL
 * Server database (no separate test DB) - accepted trade-off for a demo project under
 * time pressure. Don't point this connection at anything with real data.
 */
class MerchantModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_merchant_gets_a_valid_ulid_primary_key(): void
    {
        $merchant = Merchant::factory()->create();

        // ULID: 26 chars, Crockford base32 (excludes I/L/O/U to avoid confusion with
        // 1/0). Laravel's Str::ulid() renders it lowercase, hence the case-insensitive
        // flag. Confirms HasUlids is actually wired up, not just present on the class.
        $this->assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{26}$/i', $merchant->id);
    }

    public function test_find_by_plain_api_key_finds_the_matching_active_merchant(): void
    {
        $merchant = Merchant::factory()->withApiKey('test-plain-key')->create();

        $found = Merchant::findByPlainApiKey('test-plain-key');

        $this->assertNotNull($found);
        $this->assertTrue($found->is($merchant));
    }

    public function test_find_by_plain_api_key_returns_null_for_a_wrong_key(): void
    {
        Merchant::factory()->withApiKey('test-plain-key')->create();

        $this->assertNull(Merchant::findByPlainApiKey('some-other-key'));
    }

    public function test_find_by_plain_api_key_returns_null_for_a_deactivated_merchant(): void
    {
        // Guards against a revoked/disabled merchant's key still authenticating -
        // active=false must take effect immediately, not just get flagged somewhere.
        Merchant::factory()->withApiKey('test-plain-key')->inactive()->create();

        $this->assertNull(Merchant::findByPlainApiKey('test-plain-key'));
    }

    public function test_api_key_hash_and_webhook_secret_are_hidden_from_serialization(): void
    {
        $merchant = Merchant::factory()->create();

        $array = $merchant->toArray();

        $this->assertArrayNotHasKey('api_key_hash', $array);
        $this->assertArrayNotHasKey('webhook_secret', $array);
    }

    public function test_webhook_secret_is_encrypted_at_rest(): void
    {
        $merchant = Merchant::factory()->create(['webhook_secret' => 'plain-secret-value']);

        // Bypass Eloquent's cast to read the raw column value as SQL Server actually
        // stored it - if this ever matched the plaintext, the `encrypted` cast silently
        // stopped working (e.g. cast removed by accident) and secrets are stored in the clear.
        $rawValue = DB::table('merchants')->where('id', $merchant->id)->value('webhook_secret');

        $this->assertNotSame('plain-secret-value', $rawValue);

        // But the accessor must still transparently decrypt it back for us.
        $this->assertSame('plain-secret-value', $merchant->fresh()->webhook_secret);
    }

    public function test_mass_assignment_ignores_api_key_hash_and_webhook_secret(): void
    {
        // Simulates what would happen if a controller carelessly did
        // Merchant::create($request->all()) - the two secret fields must not be settable
        // this way, regardless of what a request sends.
        $merchant = Merchant::factory()->make(['api_key_hash' => 'original-hash']);

        $merchant->fill([
            'name' => 'Evil Co',
            'api_key_hash' => 'malicious-hash',
            'webhook_secret' => 'malicious-secret',
        ]);

        $this->assertSame('Evil Co', $merchant->name); // fillable field DID change
        $this->assertSame('original-hash', $merchant->api_key_hash); // guarded field did NOT
    }
}
