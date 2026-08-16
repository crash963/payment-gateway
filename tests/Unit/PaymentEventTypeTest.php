<?php

namespace Tests\Unit;

use App\Enums\PaymentEventType;
use App\Enums\PaymentStatus;
use PHPUnit\Framework\TestCase;
use UnhandledMatchError;

class PaymentEventTypeTest extends TestCase
{
    public function test_for_status_maps_each_reachable_status_to_its_event(): void
    {
        $this->assertSame(PaymentEventType::PaymentAuthorized, PaymentEventType::forStatus(PaymentStatus::Authorized));
        $this->assertSame(PaymentEventType::PaymentPaid, PaymentEventType::forStatus(PaymentStatus::Paid));
        $this->assertSame(PaymentEventType::PaymentFailed, PaymentEventType::forStatus(PaymentStatus::Failed));
        $this->assertSame(PaymentEventType::PaymentPartiallyRefunded, PaymentEventType::forStatus(PaymentStatus::PartiallyRefunded));
        $this->assertSame(PaymentEventType::PaymentRefunded, PaymentEventType::forStatus(PaymentStatus::Refunded));
    }

    public function test_for_status_rejects_pending_because_it_is_never_a_transition_target(): void
    {
        // Pending only ever appears as a *starting* status in the state graph (see
        // PaymentStatus::allowedTransitions - nothing transitions INTO Pending), so
        // there's deliberately no case for it here. If this ever fires, PaymentStateMachine's
        // canTransitionTo() guard was bypassed - that's a bug worth a loud crash, not a
        // silently-wrong audit log entry.
        $this->expectException(UnhandledMatchError::class);

        PaymentEventType::forStatus(PaymentStatus::Pending);
    }
}
