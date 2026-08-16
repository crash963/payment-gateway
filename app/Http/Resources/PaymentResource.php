<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Deliberately doesn't include merchant_id: it's always "the caller themselves" (a
 * merchant can only ever see/create its own payments), so echoing it back is redundant,
 * not informative. idempotency_key IS included - the merchant supplied it, so it's
 * their own data, not a secret, and useful for them to confirm the response matches
 * the request they sent.
 */
class PaymentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_id' => $this->order_id,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'status' => $this->status->value,
            'idempotency_key' => $this->idempotency_key,
            'return_url' => $this->return_url,
            'callback_url' => $this->callback_url,
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
