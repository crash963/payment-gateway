<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PaymentEventResource;
use App\Models\Payment;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Read-only audit trail for a single payment - built for the payments dashboard (see
 * resources/views/dashboard.blade.php) to show the payment_events timeline without a
 * merchant needing to go through the Copilot's getPaymentEvents tool for it. Never
 * paginated: this is always a small, bounded list scoped to one payment (a handful of
 * rows at most), unlike GET /payments which can span a merchant's whole history.
 */
class PaymentEventController extends Controller
{
    /**
     * Same 404-not-403 reasoning as PaymentController::show()/RefundController - a
     * merchant asking for another merchant's payment's events must not be able to tell
     * "doesn't exist" apart from "exists but isn't yours".
     */
    public function index(Payment $payment): AnonymousResourceCollection
    {
        abort_unless(Gate::allows('view', $payment), 404);

        return PaymentEventResource::collection($payment->paymentEvents()->oldest()->get());
    }
}
