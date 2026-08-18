<?php

return [

    /*
    | SSRF guard for outbound merchant webhook deliveries (App\Services\UrlSafetyChecker).
    | When false (the safe default - see .env.example), a webhook_url/callback_url that
    | resolves to a private/loopback/link-local/reserved IP is refused before any
    | outbound request is made. Set true only for local development and tests, where
    | the "merchant" receiver genuinely is 127.0.0.1 (see DemoMerchantWebhookReceiverController)
    | - never in production.
    */
    'allow_private_urls' => (bool) env('WEBHOOKS_ALLOW_PRIVATE_URLS', false),

    'delivery_timeout_seconds' => (int) env('WEBHOOK_DELIVERY_TIMEOUT_SECONDS', 5),

];
