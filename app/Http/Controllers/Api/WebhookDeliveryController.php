<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\WebhookDeliveryResource;
use App\Models\Payment;
use App\Models\WebhookDelivery;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Read-only delivery history for a single payment - same purpose as
 * PaymentEventController (built for the payments dashboard). Deliberately read-only:
 * *resending* a delivery stays exclusive to the Copilot's human-in-the-loop
 * resendWebhook tool (see storage/docs/12-ai-integration-copilot.md) rather than
 * duplicated here as a second write path for the same action.
 */
class WebhookDeliveryController extends Controller
{
    public function index(Payment $payment): AnonymousResourceCollection
    {
        abort_unless(Gate::allows('view', $payment), 404);

        // WebhookDelivery belongs to a PaymentEvent, not directly to a Payment (see
        // that model) - go through the payment's own events rather than a raw
        // merchant_id-scoped query, so this can never accidentally include another
        // payment's deliveries even if they happened to share a merchant_id.
        $deliveries = WebhookDelivery::query()
            ->whereIn('payment_event_id', $payment->paymentEvents()->pluck('id'))
            ->oldest('sent_at')
            ->get();

        return WebhookDeliveryResource::collection($deliveries);
    }
}
