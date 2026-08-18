<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use App\ValueObjects\Money;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    /**
     * Named paymentEvents(), not events() - Eloquent models have their own unrelated
     * "model events" concept (saving/saved/creating/...), and this being right next to
     * that word would be a constant source of "wait, which kind of event" confusion.
     */
    public function paymentEvents(): HasMany
    {
        return $this->hasMany(PaymentEvent::class);
    }

    /**
     * The one blessed way to create a Payment - sets `status` to Pending explicitly in
     * PHP via forceFill(), the same way PaymentStateMachine is the one blessed way to
     * *change* status afterwards. Callers (PaymentService) pass everything else through
     * $attributes; those still go through the normal $fillable guard via forceFill's
     * merge, they're just combined with the one field that isn't fillable.
     *
     * Why this exists instead of just `Payment::create($attributes)` relying on the
     * column's DB-level default: Eloquent has no idea a DB default fired unless the
     * model is refreshed afterwards - a bare create() leaves `status` null in memory
     * (not "pending"), which then blows up the first time anything reads it (e.g. the
     * enum cast, or PaymentResource). Setting it explicitly here means the in-memory
     * model is correct immediately, no extra refresh() round trip needed.
     */
    public static function createPending(array $attributes): self
    {
        $payment = new self;
        $payment->forceFill([...$attributes, 'status' => PaymentStatus::Pending]);
        $payment->save();

        return $payment;
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
