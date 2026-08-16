<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use App\ValueObjects\Money;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    use HasFactory;
    use HasUlids;

    /**
     * `status` is deliberately absent here. It must never move except through
     * PaymentStateMachine (which enforces PaymentStatus::canTransitionTo()) - if it were
     * fillable, a stray `Payment::create($request->all())` or `$payment->update([...])`
     * could jump straight to `paid` with no validation at all.
     */
    protected $fillable = [
        'merchant_id',
        'order_id',
        'amount',
        'currency',
        'idempotency_key',
        'return_url',
        'callback_url',
    ];

    protected $casts = [
        'amount' => 'integer', // sqlsrv's PDO driver can return bigint as a numeric string
        'status' => PaymentStatus::class,
    ];

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    /**
     * Simple accessor, not a custom Eloquent cast spanning two columns - chosen over a
     * CastsAttributes implementation to keep this step smaller. Revisit as a refactor
     * once Refund also needs a Money if the duplication becomes annoying.
     */
    public function money(): Money
    {
        return new Money($this->amount, $this->currency);
    }
}
