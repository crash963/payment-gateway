<?php

namespace Tests\Unit;

use App\Models\Merchant;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests: pure functions on Merchant that don't touch the database or need the
 * Laravel app booted (hashing, key generation). If a test needs DB or the container,
 * it belongs in tests/Feature instead - see MerchantModelTest.
 */
class MerchantApiKeyTest extends TestCase
{
    public function test_hash_api_key_is_deterministic(): void
    {
        $plainKey = 'pf_sometestkeyvalue';

        $this->assertSame(
            Merchant::hashApiKey($plainKey),
            Merchant::hashApiKey($plainKey)
        );
    }

    public function test_hash_api_key_differs_for_different_input(): void
    {
        $this->assertNotSame(
            Merchant::hashApiKey('pf_keyA'),
            Merchant::hashApiKey('pf_keyB')
        );
    }

    public function test_hash_api_key_produces_a_sha256_hex_digest(): void
    {
        $hash = Merchant::hashApiKey('pf_anything');

        // 32 bytes of SHA-256 output, hex-encoded = 64 chars, [0-9a-f] only.
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash);
    }

    public function test_generate_plain_api_key_has_the_pf_prefix(): void
    {
        $this->assertStringStartsWith('pf_', Merchant::generatePlainApiKey());
    }

    public function test_generate_plain_api_key_is_unique_across_calls(): void
    {
        // Not a formal entropy proof, just a smoke test that we're not accidentally
        // generating a constant or low-entropy value.
        $keys = array_map(fn () => Merchant::generatePlainApiKey(), range(1, 100));

        $this->assertCount(100, array_unique($keys));
    }
}
