<?php

namespace App\Enums;

use Illuminate\Support\Str;

/**
 * Which behaviour the fake provider should simulate for a given payment. Selected via
 * a "magic" order_id prefix (the same pattern Stripe uses with test card numbers,
 * e.g. 4000000000000002 = decline) - no extra field on Payment/the create-payment API,
 * it's purely a fake-provider-side concern. Anything not matching a known prefix is
 * SUCCESS, so normal testing "just works" without thinking about this at all.
 */
enum ProviderScenario: string
{
    case Success = 'success';
    case Declined = 'declined';
    case Timeout = 'timeout';
    case SlowResponse = 'slow_response';
    case DuplicateCallback = 'duplicate_callback';
    case InvalidCallback = 'invalid_callback';

    public static function fromOrderId(string $orderId): self
    {
        return match (true) {
            Str::startsWith($orderId, 'DECLINE-') => self::Declined,
            Str::startsWith($orderId, 'TIMEOUT-') => self::Timeout,
            Str::startsWith($orderId, 'SLOW-') => self::SlowResponse,
            Str::startsWith($orderId, 'DUPLICATE-') => self::DuplicateCallback,
            Str::startsWith($orderId, 'INVALID-') => self::InvalidCallback,
            default => self::Success,
        };
    }

    /**
     * The final payment outcome this scenario resolves to, once the webhook actually
     * arrives. Timeout/SlowResponse/DuplicateCallback are all about *delivery*
     * mechanics, not the underlying charge result - the money still goes through, it's
     * only the how/when/how-many-times of the notification that's being tested. Only
     * Declined represents a genuinely failed charge.
     */
    public function outcome(): PaymentStatus
    {
        return $this === self::Declined ? PaymentStatus::Failed : PaymentStatus::Paid;
    }
}
