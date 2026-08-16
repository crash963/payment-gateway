<?php

use App\Http\Controllers\Api\PaymentController;
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
    Route::post('/payments', [PaymentController::class, 'store']);
});
