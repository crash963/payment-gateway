<?php

namespace Tests\Feature\Api;

use App\Models\Merchant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * See storage/docs/14-rate-limiting.md for the full design (which limiter applies
 * where, and why). CACHE_DRIVER=file backs the rate limiter even in the testing env
 * (see .env.testing) - unlike the DB, RefreshDatabase does nothing to reset that
 * state between tests, and every /api/* request (regardless of route) also counts
 * against the global default 'api' limiter (60/min, keyed by IP in tests - see that
 * doc for why it's never merchant-keyed). Without flushing the cache around each
 * test here, an earlier test's leftover count would make these tests flaky (and,
 * worse, leftover counts from THIS test's 60+ request loops would silently 429 the
 * next unrelated test class in the same suite run).
 */
class RateLimitingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    protected function tearDown(): void
    {
        Cache::flush();

        parent::tearDown();
    }

    public function test_merchant_api_requests_beyond_the_limit_get_a_429(): void
    {
        $merchant = Merchant::factory()->withApiKey('test-key')->create();

        for ($i = 0; $i < 60; $i++) {
            $response = $this->getJson('/api/payments', ['Authorization' => 'Bearer test-key']);
            $this->assertNotEquals(429, $response->status());
        }

        $blocked = $this->getJson('/api/payments', ['Authorization' => 'Bearer test-key']);

        $blocked->assertStatus(429);
        $blocked->assertHeader('Retry-After');
    }

    public function test_a_different_merchant_has_its_own_independent_limit(): void
    {
        $first = Merchant::factory()->withApiKey('key-one')->create();
        $second = Merchant::factory()->withApiKey('key-two')->create();

        for ($i = 0; $i < 60; $i++) {
            $this->getJson('/api/payments', ['Authorization' => 'Bearer key-one']);
        }
        $this->getJson('/api/payments', ['Authorization' => 'Bearer key-one'])->assertStatus(429);

        // ApiKeyGuard caches its resolved Merchant on the guard INSTANCE (one DB hit
        // per real request - see that class). AuthManager itself then caches that
        // guard instance for the lifetime of the container. In real deployment that's
        // fine (fresh container per request - see 00-stack-decisions.md, no Octane),
        // but Laravel's HTTP test helpers reuse the SAME container across every
        // $this->getJson() call within one test method - so without this, the second
        // call below would silently keep resolving as "key-one", not because of any
        // rate-limiter bug but because the guard itself never re-authenticated.
        auth()->forgetGuards();

        // Second merchant is keyed separately (by merchant id, not IP) - exhausting
        // the first merchant's bucket must not affect this one at all.
        $this->getJson('/api/payments', ['Authorization' => 'Bearer key-two'])->assertOk();
    }

    public function test_copilot_chat_has_its_own_stricter_limit(): void
    {
        $merchant = Merchant::factory()->withApiKey('test-key')->create();

        // Empty body fails CopilotChatRequest validation (422) before ever reaching
        // CopilotService/OpenAI - cheap enough to loop past the limiter boundary
        // without needing to fake an OpenAI response for every attempt.
        for ($i = 0; $i < 10; $i++) {
            $response = $this->postJson('/api/copilot/chat', [], ['Authorization' => 'Bearer test-key']);
            $this->assertNotEquals(429, $response->status());
        }

        $blocked = $this->postJson('/api/copilot/chat', [], ['Authorization' => 'Bearer test-key']);

        $blocked->assertStatus(429);
        $blocked->assertHeader('Retry-After');
    }

    public function test_provider_webhook_requests_beyond_the_limit_get_a_429(): void
    {
        // Deliberately no/wrong signature - throttle:provider-webhook runs BEFORE
        // verify.provider.signature (see routes/api.php), so even rejected
        // (401) attempts must count against the limit.
        for ($i = 0; $i < 30; $i++) {
            $response = $this->postJson('/api/provider/webhook', []);
            $this->assertNotEquals(429, $response->status());
        }

        $blocked = $this->postJson('/api/provider/webhook', []);

        $blocked->assertStatus(429);
        $blocked->assertHeader('Retry-After');
    }
}
