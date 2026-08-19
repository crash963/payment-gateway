<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

Route::get('/', function () {
    return view('welcome');
});

// Demo-only chat UI for the AI Integration Copilot - see storage/docs for the explicit
// note on why this page itself has no login (only the API calls it makes are
// authenticated via the API key entered in the page).
Route::get('/copilot', function () {
    return view('copilot');
});

// Demo-only payments dashboard - same "no login of its own" trade-off as /copilot
// above (see storage/docs/15-payments-dashboard.md). Lets the whole create-payment ->
// async provider processing -> webhook -> refund lifecycle be demoed by clicking
// through a UI instead of typing curl/Postman requests.
Route::get('/dashboard', function () {
    return view('dashboard');
});
