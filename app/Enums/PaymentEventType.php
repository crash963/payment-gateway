<?php

namespace App\Enums;

/**
 * The audit-log vocabulary for payment_events. Most cases mirror a PaymentStatus
 * transition target (see forStatus()); PaymentCreated and the Refund* cases don't -
 * they're written directly by whatever service performs that action (create-payment
 * flow, RefundService), not by PaymentStateMachine.
 */
enum PaymentEventType: string
{
    case PaymentCreated = 'payment_created';
    case PaymentAuthorized = 'payment_authorized';
    case PaymentPaid = 'payment_paid';
    case PaymentFailed = 'payment_failed';
    case PaymentPartiallyRefunded = 'payment_partially_refunded';
    case PaymentRefunded = 'payment_refunded';
    case RefundCreated = 'refund_created';
    case RefundCompleted = 'refund_completed';

    /**
     * Maps a PaymentStatus that a payment was just moved TO, to the event type that
     * records it. Deliberately has no `PaymentStatus::Pending` arm: Pending never
     * appears as a transition target in the state graph (see PaymentStatus), so if this
     * ever got called with Pending, that means PaymentStateMachine's guard was bypassed
     * somehow - an UnhandledMatchError here is the correct loud failure for that bug,
     * not something to paper over with a silent default.
     */
    public static function forStatus(PaymentStatus $status): self
    {
        return match ($status) {
            PaymentStatus::Authorized => self::PaymentAuthorized,
            PaymentStatus::Paid => self::PaymentPaid,
            PaymentStatus::Failed => self::PaymentFailed,
            PaymentStatus::PartiallyRefunded => self::PaymentPartiallyRefunded,
            PaymentStatus::Refunded => self::PaymentRefunded,
        };
    }
}
