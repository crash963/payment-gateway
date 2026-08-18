<?php

namespace App\Services\Copilot\Tools;

use App\Http\Resources\PaymentResource;
use App\Models\Merchant;
use App\Models\Payment;

class GetPaymentTool implements CopilotTool
{
    public function name(): string
    {
        return 'getPayment';
    }

    public function description(): string
    {
        return "Look up a single payment belonging to the merchant, by its id. Returns the payment's current status, amount, currency, and timestamps.";
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'payment_id' => [
                    'type' => 'string',
                    'description' => 'The payment ID (ULID), e.g. as mentioned by the merchant or seen in a webhook payload.',
                ],
            ],
            'required' => ['payment_id'],
        ];
    }

    public function execute(Merchant $merchant, array $arguments): array
    {
        $payment = Payment::query()
            ->where('merchant_id', $merchant->id)
            ->find($arguments['payment_id'] ?? null);

        if (! $payment) {
            // Same "don't distinguish doesn't-exist from not-yours" instinct as the
            // REST API's 404s - the tool result is what the model sees, so it's what
            // could end up (indirectly) surfaced to the merchant.
            return ['error' => 'No payment with that id was found for this merchant.'];
        }

        return (new PaymentResource($payment))->resolve();
    }
}
