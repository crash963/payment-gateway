<?php

namespace App\Policies;

use App\Models\Merchant;
use App\Models\Payment;

/**
 * Only `view` exists for now - deliberately not stubbing out viewAny/create/update/
 * delete/restore/forceDelete just because the generator scaffolds them. There's no
 * "delete a payment" or "update a payment" operation anywhere in this API (payments
 * only change through PaymentStateMachine/RefundService), viewAny isn't meaningful
 * since GET /api/payments is always pre-scoped to the caller's own merchant_id in the
 * query itself rather than filtered item-by-item through a Policy, and `create` doesn't
 * need a Policy - see StorePaymentRequest::authorize() for why authentication alone is
 * enough there. Add the rest only when a real operation needs them.
 */
class PaymentPolicy
{
    /**
     * A merchant may only view its own payments. Deliberately NOT wired to
     * `$this->authorize()`'s default 403 response - see PaymentController::show() and
     * storage/docs for why a denial here is translated to a 404 instead, to avoid
     * confirming that a payment ID belonging to another merchant exists at all.
     */
    public function view(Merchant $merchant, Payment $payment): bool
    {
        return $merchant->id === $payment->merchant_id;
    }
}
