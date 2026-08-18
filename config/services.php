<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    /*
    | Shared secret between PayFlow and the fake provider, for signing
    | provider -> PayFlow webhooks (POST /api/provider/webhook). Deliberately NOT a
    | merchant's webhook_secret - that key signs PayFlow -> merchant webhooks, a
    | completely different trust boundary. There's exactly one provider (fake, for
    | now), so one global secret is correct here, not a per-merchant one.
    */
    'fake_provider' => [
        'webhook_secret' => env('FAKE_PROVIDER_WEBHOOK_SECRET'),

        // How long FakeProviderController sleeps for the TIMEOUT/SLOW_RESPONSE
        // scenarios (seconds). Configurable specifically so the test suite (see
        // .env.testing) can set these to 0 - the scenario-selection/job-dispatch logic
        // is worth testing, but literally waiting out a multi-second sleep in every
        // test run isn't. Real delays (3s/1s) apply for local manual testing.
        'timeout_delay_seconds' => (int) env('FAKE_PROVIDER_TIMEOUT_DELAY_SECONDS', 3),
        'slow_response_delay_seconds' => (int) env('FAKE_PROVIDER_SLOW_RESPONSE_DELAY_SECONDS', 1),
    ],

    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        // gpt-4o-mini: cheap and fast enough for a tool-calling loop, plenty capable
        // for grounded Q&A over our own docs/data. Configurable in case that changes.
        'model' => env('OPENAI_MODEL', 'gpt-4o-mini'),
        'timeout_seconds' => (int) env('OPENAI_TIMEOUT_SECONDS', 30),
    ],

];
