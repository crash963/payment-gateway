<?php

namespace App\Services\Copilot\Tools;

use App\Models\Merchant;
use App\Services\Copilot\Tools\Concerns\FindsMerchantPayment;

class GetPaymentEventsTool implements CopilotTool
{
    use FindsMerchantPayment;

    public function name(): string
    {
        return 'getPaymentEvents';
    }

    public function description(): string
    {
        return 'Get the full audit event history for one of the merchant\'s payments (created, authorized, paid, failed, refunded, etc.) in chronological order.';
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
        // Ownership check happens by requiring the payment to belong to $merchant
        // BEFORE ever touching payment_events - there's no path here that could return
        // another merchant's event history even for a payment id that exists.
        $payment = $this->findMerchantPayment($merchant, $arguments);

        if (is_array($payment)) {
            return $payment;
        }

        return [
            'payment_id' => $payment->id,
            'events' => $payment->paymentEvents()
                ->orderBy('created_at')
                ->get()
                ->map(fn ($event) => [
                    'type' => $event->type->value,
                    'metadata' => $event->metadata,
                    'created_at' => $event->created_at->toIso8601String(),
                ])
                ->all(),
        ];
    }
}
