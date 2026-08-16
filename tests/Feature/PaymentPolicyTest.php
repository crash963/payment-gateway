<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Models\Payment;
use App\Policies\PaymentPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_merchant_can_view_its_own_payment(): void
    {
        $merchant = Merchant::factory()->create();
        $payment = Payment::factory()->for($merchant)->create();

        $this->assertTrue((new PaymentPolicy)->view($merchant, $payment));
    }

    public function test_a_merchant_cannot_view_another_merchants_payment(): void
    {
        $owner = Merchant::factory()->create();
        $other = Merchant::factory()->create();
        $payment = Payment::factory()->for($owner)->create();

        $this->assertFalse((new PaymentPolicy)->view($other, $payment));
    }
}
