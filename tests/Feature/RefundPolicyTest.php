<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Models\Payment;
use App\Models\Refund;
use App\Policies\RefundPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RefundPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_merchant_can_view_a_refund_on_its_own_payment(): void
    {
        $merchant = Merchant::factory()->create();
        $payment = Payment::factory()->for($merchant)->create();
        $refund = Refund::factory()->for($payment)->create();

        $this->assertTrue((new RefundPolicy)->view($merchant, $refund));
    }

    public function test_a_merchant_cannot_view_a_refund_on_another_merchants_payment(): void
    {
        $owner = Merchant::factory()->create();
        $other = Merchant::factory()->create();
        $payment = Payment::factory()->for($owner)->create();
        $refund = Refund::factory()->for($payment)->create();

        $this->assertFalse((new RefundPolicy)->view($other, $refund));
    }
}
