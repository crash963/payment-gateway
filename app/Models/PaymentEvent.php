<?php

namespace App\Models;

use App\Enums\PaymentEventType;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class PaymentEvent extends Model
{
    use HasFactory;
    use HasUlids;

    /**
     * No updated_at column exists (see migration) - telling Eloquent that stops it
     * trying to write one on save.
     */
    const UPDATED_AT = null;

    protected $fillable = [
        'payment_id',
        'type',
        'metadata',
    ];

    protected $casts = [
        'type' => PaymentEventType::class,
        'metadata' => 'array',
    ];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /**
     * Append-only enforcement: block updating/deleting an already-persisted event
     * instance. Caveat documented in storage/docs - this doesn't catch a bulk
     * `PaymentEvent::where(...)->update(...)` via the query builder, since that never
     * fires Eloquent model events. A real production guarantee would need a DB-level
     * trigger or a restricted DB user grant; this is the pragmatic app-layer version.
     */
    protected static function booted(): void
    {
        static::updating(function () {
            throw new LogicException('PaymentEvent records are immutable and cannot be updated.');
        });

        static::deleting(function () {
            throw new LogicException('PaymentEvent records are immutable and cannot be deleted.');
        });
    }
}
