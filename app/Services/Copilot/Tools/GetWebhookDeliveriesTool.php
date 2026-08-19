<?php

namespace App\Services\Copilot\Tools;

use App\Models\Merchant;
use App\Models\WebhookDelivery;
use App\Services\Copilot\Tools\Concerns\FindsMerchantPayment;

/**
 * The tool behind the exact diagnostic scenario from the spec: "payment is paid but my
 * order didn't update" - a merchant asking this needs to see that PayFlow tried to
 * tell them (attempt 1 -> HTTP 500, attempt 2 -> HTTP 500, attempt 3 -> HTTP 401) so
 * the model can conclude the problem is on the merchant's own endpoint.
 */
class GetWebhookDeliveriesTool implements CopilotTool
{
    use FindsMerchantPayment;

    public function name(): string
    {
        return 'getWebhookDeliveries';
    }

    public function description(): string
    {
        return 'Get the webhook delivery attempt history (URL, HTTP status, response, timestamp for each attempt) for one of the merchant\'s payments - use this to diagnose "payment succeeded but my system never found out" style problems.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'payment_id' => ['type' => 'string', 'description' => 'The payment ID (ULID).'],
            ],
            'required' => ['payment_id'],
        ];
    }

    public function execute(Merchant $merchant, array $arguments): array
    {
        $payment = $this->findMerchantPayment($merchant, $arguments);

        if (is_array($payment)) {
            return $payment;
        }

        // Scoped both by merchant_id directly AND by only this payment's events - two
        // independent reasons the WHERE clauses below can never leak another
        // merchant's or another payment's deliveries.
        $deliveries = WebhookDelivery::query()
            ->where('merchant_id', $merchant->id)
            ->whereIn('payment_event_id', $payment->paymentEvents()->pluck('id'))
            ->orderBy('sent_at')
            ->get();

        return [
            'payment_id' => $payment->id,
            'deliveries' => $deliveries->map(fn (WebhookDelivery $d) => [
                'webhook_delivery_id' => $d->id,
                'url' => $d->url,
                'attempt' => $d->attempt,
                'http_status' => $d->http_status,
                'successful' => $d->successful,
                'response' => $d->response,
                'sent_at' => $d->sent_at->toIso8601String(),
            ])->all(),
        ];
    }
}
