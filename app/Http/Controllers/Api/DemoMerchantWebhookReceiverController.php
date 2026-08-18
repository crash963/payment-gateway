<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Merchant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Stands in for a merchant's own server - in a real deployment, THIS code would live
 * on the merchant's infrastructure, not ours. It exists purely so the whole delivery
 * loop (sign -> send -> verify -> respond) is demonstrable end-to-end on localhost,
 * the same role FakeProviderController plays for the payment-provider side.
 *
 * Looks up the merchant from the payload's `merchant_id` to know which
 * `webhook_secret` to verify against - see DeliverMerchantWebhookJob's payload
 * comment for why that field is present here but not in the public API's
 * PaymentResource.
 */
class DemoMerchantWebhookReceiverController extends Controller
{
    public function receive(Request $request): JsonResponse
    {
        $payload = $request->json()->all();
        $merchant = isset($payload['merchant_id']) ? Merchant::find($payload['merchant_id']) : null;

        $signature = $request->header('X-PayFlow-Signature');
        $expected = $merchant ? hash_hmac('sha256', $request->getContent(), $merchant->webhook_secret) : null;
        $signatureValid = $expected && $signature && hash_equals($expected, $signature);

        Log::info('[demo merchant receiver] webhook received', [
            'signature_valid' => $signatureValid,
            'type' => $payload['type'] ?? null,
            'payment_id' => $payload['payment']['id'] ?? null,
            'order_id' => $payload['payment']['order_id'] ?? null,
        ]);

        // Scenario control via the same "magic" order_id prefix pattern as
        // ProviderScenario - lets us demo retry/backoff on demand instead of waiting
        // for a real merchant server to actually be flaky.
        $orderId = $payload['payment']['order_id'] ?? '';

        if (Str::startsWith($orderId, 'WEBHOOKFAIL-')) {
            return response()->json(['error' => 'simulated merchant server error'], 500);
        }

        return response()->json(['received' => true], 200);
    }
}
