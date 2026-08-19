<?php

namespace App\Services\Copilot\Tools\Concerns;

use App\Models\Merchant;
use App\Models\Payment;

/**
 * Shared by every Copilot tool that looks up a payment by id (GetPaymentTool,
 * GetPaymentEventsTool, GetWebhookDeliveriesTool) - extracted in code review after the
 * identical merchant-scoped lookup (and identical not-found error string) had been
 * copy-pasted into all three. This lookup IS the actual enforcement point for "the
 * agent must never see another merchant's data" (see CopilotTool's docblock) - having
 * it expressed once, not three times, means a future tool can't accidentally skip or
 * subtly mis-scope it by not copying the pattern exactly.
 */
trait FindsMerchantPayment
{
    /**
     * @param  array<string, mixed>  $arguments
     * @return Payment|array{error: string}
     */
    private function findMerchantPayment(Merchant $merchant, array $arguments): Payment|array
    {
        $payment = Payment::query()
            ->where('merchant_id', $merchant->id)
            ->find($arguments['payment_id'] ?? null);

        return $payment ?? ['error' => 'No payment with that id was found for this merchant.'];
    }
}
