<?php

namespace App\Policies;

use App\Models\Merchant;
use App\Models\Refund;

/**
 * Only used by the standalone GET /api/refunds/{refund} route - the nested
 * .../payments/{payment}/refunds routes reuse PaymentPolicy::view() on the parent
 * payment instead (if you can't see the payment, you can't see its refunds either;
 * no separate check needed there).
 */
class RefundPolicy
{
    public function view(Merchant $merchant, Refund $refund): bool
    {
        return $merchant->id === $refund->payment->merchant_id;
    }
}
