<?php

use App\Http\Controllers\Api\CopilotController;
use App\Http\Controllers\Api\DemoMerchantWebhookReceiverController;
use App\Http\Controllers\Api\FakeProviderController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\PaymentEventController;
use App\Http\Controllers\Api\ProviderWebhookController;
use App\Http\Controllers\Api\RefundController;
use App\Http\Controllers\Api\WebhookDeliveryController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

// Removed the skeleton's default `auth:sanctum` /user route - we deliberately don't
// use Sanctum (see storage/docs/00-stack-decisions.md), so it referenced a guard that
// was never configured.

Route::middleware('auth:merchant')->group(function () {
    // throttle:merchant-api runs AFTER auth:merchant here (route-level middleware
    // order), so it sees the resolved Merchant and keys by merchant id - see
    // RouteServiceProvider for why that's not true of the outer 'api' group's own
    // default limiter.
    Route::middleware('throttle:merchant-api')->group(function () {
        Route::get('/payments', [PaymentController::class, 'index']);
        Route::post('/payments', [PaymentController::class, 'store']);
        Route::get('/payments/{payment}', [PaymentController::class, 'show']);

        Route::get('/payments/{payment}/refunds', [RefundController::class, 'index']);
        Route::post('/payments/{payment}/refunds', [RefundController::class, 'store']);
        Route::get('/refunds/{refund}', [RefundController::class, 'show']);

        // Built for the payments dashboard (resources/views/dashboard.blade.php) -
        // read-only audit trail, see those controllers for why each is its own thin
        // single-purpose class rather than more methods bolted onto PaymentController.
        Route::get('/payments/{payment}/events', [PaymentEventController::class, 'index']);
        Route::get('/payments/{payment}/webhook-deliveries', [WebhookDeliveryController::class, 'index']);
    });

    // Own, much stricter limiter (see RouteServiceProvider) - separate from
    // merchant-api because the cost profile is completely different (real OpenAI
    // spend per call, not just app load).
    Route::middleware('throttle:copilot')->post('/copilot/chat', [CopilotController::class, 'chat']);
});

// Stands in for an external payment processor - see FakeProviderController. No
// "merchant" auth: this isn't a merchant-facing route at all. No dedicated rate
// limiter either - relies only on the global 'api' group default (60/min/IP) -
// deliberate, see storage/docs/14-rate-limiting.md: this route "would never exist in
// a real deployment" (it's a stand-in for a real external processor), so a stricter
// dedicated limit would just be exercise, not real protection.
Route::post('/fake-provider/charge', [FakeProviderController::class, 'charge']);

// The provider calling back into PayFlow - protected by HMAC signature, not merchant
// auth (see VerifyProviderWebhookSignature for why those are different trust models).
// throttle:provider-webhook runs BEFORE the signature check on purpose, so a flood of
// invalid-signature attempts is capped too, not just valid ones.
Route::middleware(['throttle:provider-webhook', 'verify.provider.signature'])->post('/provider/webhook', [ProviderWebhookController::class, 'handle']);

// Stands in for a merchant's own server, for local demo purposes only - see
// DemoMerchantWebhookReceiverController. Would never exist in a real deployment - same
// "global default only" reasoning as /fake-provider/charge above.
Route::post('/demo/webhook-receiver', [DemoMerchantWebhookReceiverController::class, 'receive']);
