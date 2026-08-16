<?php

namespace Tests\Feature;

use App\Models\Merchant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * No real protected endpoints exist yet (that's the REST API step), so this defines
 * throwaway routes to exercise the "merchant" guard/auth:merchant middleware in
 * isolation - a standard pattern for testing middleware before the routes that will
 * actually use it exist.
 */
class ApiKeyAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('auth:merchant')->get('/__test/whoami', function (Request $request) {
            return response()->json(['merchant_id' => $request->user()->id]);
        });

        // No guard named explicitly - proves "merchant" really is the default guard.
        Route::middleware('auth')->get('/__test/whoami-default-guard', function (Request $request) {
            return response()->json(['merchant_id' => $request->user()->id]);
        });
    }

    public function test_a_valid_api_key_authenticates_the_request(): void
    {
        $merchant = Merchant::factory()->withApiKey('test-plain-key')->create();

        $response = $this->getJson('/__test/whoami', [
            'Authorization' => 'Bearer test-plain-key',
        ]);

        $response->assertOk();
        $response->assertJson(['merchant_id' => $merchant->id]);
    }

    public function test_the_default_guard_is_the_merchant_guard(): void
    {
        $merchant = Merchant::factory()->withApiKey('test-plain-key')->create();

        $response = $this->getJson('/__test/whoami-default-guard', [
            'Authorization' => 'Bearer test-plain-key',
        ]);

        $response->assertOk();
        $response->assertJson(['merchant_id' => $merchant->id]);
    }

    public function test_a_missing_authorization_header_is_rejected(): void
    {
        $response = $this->getJson('/__test/whoami');

        $response->assertStatus(401);
        $response->assertJson(['error' => ['code' => 'unauthenticated']]);
    }

    public function test_a_wrong_api_key_is_rejected(): void
    {
        Merchant::factory()->withApiKey('test-plain-key')->create();

        $response = $this->getJson('/__test/whoami', [
            'Authorization' => 'Bearer wrong-key',
        ]);

        $response->assertStatus(401);
    }

    public function test_a_deactivated_merchants_key_is_rejected(): void
    {
        Merchant::factory()->withApiKey('test-plain-key')->inactive()->create();

        $response = $this->getJson('/__test/whoami', [
            'Authorization' => 'Bearer test-plain-key',
        ]);

        $response->assertStatus(401);
    }
}
