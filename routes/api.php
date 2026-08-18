<?php

use App\Http\Controllers\Api\FakeProviderController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\ProviderWebhookController;
use App\Http\Controllers\Api\RefundController;
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
    Route::get('/payments', [PaymentController::class, 'index']);
    Route::post('/payments', [PaymentController::class, 'store']);
    Route::get('/payments/{payment}', [PaymentController::class, 'show']);

    Route::get('/payments/{payment}/refunds', [RefundController::class, 'index']);
    Route::post('/payments/{payment}/refunds', [RefundController::class, 'store']);
    Route::get('/refunds/{refund}', [RefundController::class, 'show']);
});

// Stands in for an external payment processor - see FakeProviderController. No
// "merchant" auth: this isn't a merchant-facing route at all.
Route::post('/fake-provider/charge', [FakeProviderController::class, 'charge']);

// The provider calling back into PayFlow - protected by HMAC signature, not merchant
// auth (see VerifyProviderWebhookSignature for why those are different trust models).
Route::middleware('verify.provider.signature')->post('/provider/webhook', [ProviderWebhookController::class, 'handle']);
