<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One row per delivery ATTEMPT (see migration) - this is what makes a retried delivery
 * show up as history rather than overwriting a single "last known state" row.
 */
class WebhookDelivery extends Model
{
    use HasFactory;
    use HasUlids;

    const UPDATED_AT = null;

    // The migration names this column `sent_at` (matches the spec's field name),
    // not Eloquent's default `created_at` - without this override, Eloquent tries to
    // insert into a `created_at` column that doesn't exist on this table.
    const CREATED_AT = 'sent_at';

    protected $fillable = [
        'payment_event_id',
        'merchant_id',
        'url',
        'attempt',
        'http_status',
        'response',
        'successful',
    ];

    protected $casts = [
        'attempt' => 'integer',
        'http_status' => 'integer',
        'successful' => 'boolean',
    ];

    public function paymentEvent(): BelongsTo
    {
        return $this->belongsTo(PaymentEvent::class);
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    /**
     * Immutable delivery log - same reasoning (and same bulk-update-via-query-builder
     * caveat) as PaymentEvent/Refund.
     */
    protected static function booted(): void
    {
        static::updating(function () {
            throw new LogicException('WebhookDelivery records are immutable and cannot be updated.');
        });

        static::deleting(function () {
            throw new LogicException('WebhookDelivery records are immutable and cannot be deleted.');
        });
    }
}
