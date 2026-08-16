<?php

namespace Database\Factories;

use App\Models\Merchant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Merchant>
 */
class MerchantFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            // A real, but throwaway, plaintext key is hashed here. Tests that need to
            // authenticate *as* this merchant (i.e. need the plaintext) should use the
            // withApiKey() state below instead of relying on this one, since this
            // plaintext is generated and discarded - it's never retrievable again, which
            // is the whole point of hashing it (see Merchant model comment).
            'api_key_hash' => Merchant::hashApiKey(Merchant::generatePlainApiKey()),
            'webhook_secret' => Str::random(40),
            'active' => true,
        ];
    }

    /**
     * Build the model instance via forceFill() instead of the constructor.
     *
     * Why this override is needed: Merchant::$fillable deliberately excludes
     * api_key_hash/webhook_secret (see model comment - they must never be settable via
     * mass-assigned HTTP input). But Eloquent's default newModel() does `new Merchant($attributes)`,
     * which goes through the constructor's fill() and therefore the *same* fillable guard -
     * so api_key_hash/webhook_secret from definition() would be silently dropped, and the
     * insert would then fail on the NOT NULL constraint for those columns. A factory is
     * trusted internal test/seed code, not untrusted HTTP input, so it's correct for it to
     * bypass the guard here via forceFill() rather than relaxing $fillable on the model itself.
     */
    public function newModel(array $attributes = [])
    {
        return (new Merchant)->forceFill($attributes);
    }

    /**
     * Set a caller-known plaintext API key, so tests can authenticate as this merchant.
     *
     * Usage: Merchant::factory()->withApiKey('test-plain-key')->create();
     * then send `Authorization: Bearer test-plain-key` in the test request.
     */
    public function withApiKey(string $plainKey): static
    {
        return $this->state(fn (array $attributes) => [
            'api_key_hash' => Merchant::hashApiKey($plainKey),
        ]);
    }

    /**
     * Deactivated merchant - useful for testing that a revoked API key stops authenticating.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'active' => false,
        ]);
    }
}
