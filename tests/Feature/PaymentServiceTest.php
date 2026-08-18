<?php

namespace Tests\Feature;

use App\Enums\PaymentEventType;
use App\Exceptions\IdempotencyKeyConflictException;
use App\Models\Merchant;
use App\Models\PaymentEvent;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Covers the idempotency logic directly (no HTTP layer) - see
 * tests/Feature/Api/CreatePaymentTest.php for the same behaviour exercised through the
 * real endpoint. Doesn't (and can't, in a single-process synchronous test) exercise the
 * QueryException/race-recovery branch directly - see PaymentService's class doc for why
 * that's an accepted limitation here.
 */
class PaymentServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // PaymentService::create() dispatches InitiatePaymentWithProviderJob, which
        // runs synchronously under the `sync` queue driver phpunit.xml forces for
        // tests - so every create() call here really does make an outbound HTTP call
        // to the fake provider. This suite isn't testing that integration (see
        // InitiatePaymentWithProviderJobTest/FakeProviderChargeTest for that), so a
        // generic 202 is enough for every test in this class - none of them care about
        // the provider's response, only that create() itself behaves correctly.
        Http::fake(['*/api/fake-provider/charge' => Http::response(['received' => true], 202)]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'idempotency_key' => 'idem-1',
            'order_id' => 'order-1',
            'amount' => 10000,
            'currency' => 'CZK',
            'return_url' => null,
            'callback_url' => null,
        ], $overrides);
    }

    public function test_creates_a_new_payment(): void
    {
        $merchant = Merchant::factory()->create();

        $result = (new PaymentService)->create($merchant, $this->payload());

        $this->assertTrue($result['created']);
        $this->assertSame($merchant->id, $result['payment']->merchant_id);
        $this->assertSame('order-1', $result['payment']->order_id);
    }

    public function test_writes_a_payment_created_event(): void
    {
        $merchant = Merchant::factory()->create();

        $result = (new PaymentService)->create($merchant, $this->payload());

        $event = PaymentEvent::where('payment_id', $result['payment']->id)->sole();
        $this->assertSame(PaymentEventType::PaymentCreated, $event->type);
    }

    public function test_repeating_the_same_request_replays_the_original_payment(): void
    {
        $merchant = Merchant::factory()->create();
        $service = new PaymentService;

        $first = $service->create($merchant, $this->payload());
        $second = $service->create($merchant, $this->payload());

        $this->assertTrue($first['created']);
        $this->assertFalse($second['created']);
        $this->assertTrue($first['payment']->is($second['payment']));

        // No duplicate PaymentCreated event for the replay.
        $this->assertSame(1, PaymentEvent::where('payment_id', $first['payment']->id)->count());
    }

    public function test_reusing_the_key_with_a_different_amount_conflicts(): void
    {
        $merchant = Merchant::factory()->create();
        $service = new PaymentService;

        $service->create($merchant, $this->payload(['amount' => 10000]));

        $this->expectException(IdempotencyKeyConflictException::class);

        $service->create($merchant, $this->payload(['amount' => 20000]));
    }

    public function test_reusing_the_key_with_a_different_order_id_conflicts(): void
    {
        $merchant = Merchant::factory()->create();
        $service = new PaymentService;

        $service->create($merchant, $this->payload(['order_id' => 'order-1']));

        $this->expectException(IdempotencyKeyConflictException::class);

        $service->create($merchant, $this->payload(['order_id' => 'order-2']));
    }

    public function test_different_merchants_may_reuse_the_same_idempotency_key(): void
    {
        $merchantA = Merchant::factory()->create();
        $merchantB = Merchant::factory()->create();
        $service = new PaymentService;

        $resultA = $service->create($merchantA, $this->payload(['idempotency_key' => 'shared']));
        $resultB = $service->create($merchantB, $this->payload(['idempotency_key' => 'shared']));

        $this->assertTrue($resultA['created']);
        $this->assertTrue($resultB['created']);
        $this->assertNotSame($resultA['payment']->id, $resultB['payment']->id);
    }
}
