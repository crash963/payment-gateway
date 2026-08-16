<?php

namespace Tests\Unit;

use App\Enums\PaymentStatus;
use PHPUnit\Framework\TestCase;

/**
 * Pure enum logic, no DB/app - see storage/docs/03-payment-state-machine.md for the
 * diagram this test asserts against.
 */
class PaymentStatusTest extends TestCase
{
    /**
     * Every edge NOT in this list must be rejected. Written as a full cross-product
     * check (not just a handful of examples) so that adding a new status later without
     * updating allowedTransitions() - or accidentally opening up an edge that shouldn't
     * exist - fails loudly here instead of surfacing as a payment stuck in the wrong
     * state in production.
     */
    public function test_only_the_documented_edges_are_allowed(): void
    {
        $validEdges = [
            [PaymentStatus::Pending, PaymentStatus::Authorized],
            [PaymentStatus::Pending, PaymentStatus::Paid],
            [PaymentStatus::Pending, PaymentStatus::Failed],
            [PaymentStatus::Authorized, PaymentStatus::Paid],
            [PaymentStatus::Authorized, PaymentStatus::Failed],
            [PaymentStatus::Paid, PaymentStatus::PartiallyRefunded],
            [PaymentStatus::Paid, PaymentStatus::Refunded],
            [PaymentStatus::PartiallyRefunded, PaymentStatus::Refunded],
        ];

        foreach (PaymentStatus::cases() as $from) {
            foreach (PaymentStatus::cases() as $to) {
                if ($from === $to) {
                    continue;
                }

                $expected = in_array([$from, $to], $validEdges, true);

                $this->assertSame(
                    $expected,
                    $from->canTransitionTo($to),
                    "canTransitionTo() mismatch for {$from->value} -> {$to->value}"
                );
            }
        }
    }

    public function test_refunded_and_failed_are_terminal(): void
    {
        $this->assertTrue(PaymentStatus::Refunded->isTerminal());
        $this->assertTrue(PaymentStatus::Failed->isTerminal());
    }

    public function test_non_terminal_statuses(): void
    {
        $this->assertFalse(PaymentStatus::Pending->isTerminal());
        $this->assertFalse(PaymentStatus::Authorized->isTerminal());
        $this->assertFalse(PaymentStatus::Paid->isTerminal());
        $this->assertFalse(PaymentStatus::PartiallyRefunded->isTerminal());
    }

    public function test_a_refund_cannot_be_reversed_back_to_paid(): void
    {
        // Specifically calling this out beyond the cross-product test above because
        // it's the one an interviewer is most likely to probe: "what stops someone
        // from moving a refunded payment back to paid?"
        $this->assertFalse(PaymentStatus::PartiallyRefunded->canTransitionTo(PaymentStatus::Paid));
        $this->assertFalse(PaymentStatus::Refunded->canTransitionTo(PaymentStatus::Paid));
    }
}
