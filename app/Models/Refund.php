<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class Refund extends Model
{
    use HasFactory;
    use HasUlids;

    const UPDATED_AT = null;

    protected $fillable = [
        'payment_id',
        'amount',
        'idempotency_key',
    ];

    protected $casts = [
        'amount' => 'integer',
    ];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /**
     * A refund is a financial record - once created it's never modified or deleted.
     * Same immutability guard (and same bulk-update-via-query-builder caveat) as
     * PaymentEvent - see that model.
     */
    protected static function booted(): void
    {
        static::updating(function () {
            throw new LogicException('Refund records are immutable and cannot be updated.');
        });

        static::deleting(function () {
            throw new LogicException('Refund records are immutable and cannot be deleted.');
        });
    }
}
