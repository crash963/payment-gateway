<?php

namespace App\Services\Copilot\Tools;

use App\Http\Resources\PaymentResource;
use App\Models\Merchant;
use App\Services\Copilot\Tools\Concerns\FindsMerchantPayment;

class GetPaymentTool implements CopilotTool
{
    use FindsMerchantPayment;

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
        $payment = $this->findMerchantPayment($merchant, $arguments);

        if (is_array($payment)) {
            return $payment;
        }

        return (new PaymentResource($payment))->resolve();
    }
}
