<?php

namespace App\Services\Copilot\Tools;

use App\Jobs\DeliverMerchantWebhookJob;
use App\Models\Merchant;
use App\Models\WebhookDelivery;

/**
 * The one WRITE tool the copilot has - human-in-the-loop by construction, not by
 * convention: `confirmed` defaults to false, and the tool's own result (not just its
 * description) tells the model it must describe the action and get an explicit yes
 * before ever calling this again with confirmed=true. There's no separate
 * confirmation endpoint/session state - the model is expected to re-invoke this same
 * tool with confirmed=true once the merchant has agreed, within the same stateless
 * conversation (see CopilotService's system prompt).
 *
 * Reuses DeliverMerchantWebhookJob rather than reimplementing delivery - a resend
 * should behave identically to the original attempt (same signing, same SSRF check,
 * same retry/backoff if it fails again), so it goes through the exact same code path.
 */
class ResendWebhookTool implements CopilotTool
{
    public function name(): string
    {
        return 'resendWebhook';
    }

    public function description(): string
    {
        return 'Resend a webhook delivery to the merchant\'s own endpoint. This is a WRITE action with a real side effect - call it first with confirmed omitted/false to describe exactly what will be resent and to where, then ONLY call it again with confirmed=true after the merchant has explicitly agreed in this conversation.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'webhook_delivery_id' => [
                    'type' => 'string',
                    'description' => 'The id of a previous webhook delivery attempt (from getWebhookDeliveries) to resend the underlying event for.',
                ],
                'confirmed' => [
                    'type' => 'boolean',
                    'description' => 'Must be true to actually perform the resend. Omit or set false to preview the action instead.',
                ],
            ],
            'required' => ['webhook_delivery_id'],
        ];
    }

    public function execute(Merchant $merchant, array $arguments): array
    {
        $delivery = WebhookDelivery::query()
            ->where('merchant_id', $merchant->id)
            ->find($arguments['webhook_delivery_id'] ?? null);

        if (! $delivery) {
            return ['error' => 'No webhook delivery with that id was found for this merchant.'];
        }

        if (empty($arguments['confirmed'])) {
            return [
                'requires_confirmation' => true,
                'action' => 'resend_webhook',
                'details' => [
                    'webhook_delivery_id' => $delivery->id,
                    'destination_url' => $delivery->url,
                    'original_attempt' => $delivery->attempt,
                    'original_http_status' => $delivery->http_status,
                ],
                'instruction_to_assistant' => 'Describe this to the merchant and ask them to confirm before calling resendWebhook again with confirmed=true.',
            ];
        }

        DeliverMerchantWebhookJob::dispatch($delivery->payment_event_id);

        return [
            'status' => 'resend_dispatched',
            'webhook_delivery_id' => $delivery->id,
        ];
    }
}
