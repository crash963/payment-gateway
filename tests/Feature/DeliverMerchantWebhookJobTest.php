<?php

namespace Tests\Feature;

use App\Enums\PaymentEventType;
use App\Exceptions\WebhookDeliveryFailedException;
use App\Jobs\DeliverMerchantWebhookJob;
use App\Models\Merchant;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\WebhookDelivery;
use App\Services\UrlSafetyChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DeliverMerchantWebhookJobTest extends TestCase
{
    use RefreshDatabase;

    private function eventFor(Payment $payment, PaymentEventType $type = PaymentEventType::PaymentPaid): PaymentEvent
    {
        return PaymentEvent::factory()->for($payment)->create(['type' => $type]);
    }

    public function test_a_successful_delivery_is_signed_correctly_and_recorded(): void
    {
        Http::fake(['*' => Http::response(['received' => true], 200)]);

        $merchant = Merchant::factory()->create(['webhook_url' => 'http://8.8.8.8/hook']);
        $payment = Payment::factory()->for($merchant)->paid()->create();
        $event = $this->eventFor($payment);

        (new DeliverMerchantWebhookJob($event->id))->handle(app(UrlSafetyChecker::class));

        Http::assertSent(function ($request) use ($merchant, $event) {
            $body = json_decode($request->body(), true);
            $expectedSignature = hash_hmac('sha256', $request->body(), $merchant->webhook_secret);

            return $request->url() === 'http://8.8.8.8/hook'
                && $body['event_id'] === $event->id
                && $body['type'] === 'payment.paid'
                && $body['merchant_id'] === $merchant->id
                && $request->header('X-PayFlow-Signature')[0] === $expectedSignature;
        });

        $delivery = WebhookDelivery::sole();
        $this->assertSame(1, $delivery->attempt);
        $this->assertSame(200, $delivery->http_status);
        $this->assertTrue($delivery->successful);
    }

    public function test_a_non_2xx_response_is_recorded_and_throws_to_trigger_a_retry(): void
    {
        Http::fake(['*' => Http::response(['error' => 'server error'], 500)]);

        $merchant = Merchant::factory()->create(['webhook_url' => 'http://8.8.8.8/hook']);
        $payment = Payment::factory()->for($merchant)->paid()->create();
        $event = $this->eventFor($payment);

        $this->expectException(WebhookDeliveryFailedException::class);

        try {
            (new DeliverMerchantWebhookJob($event->id))->handle(app(UrlSafetyChecker::class));
        } finally {
            $delivery = WebhookDelivery::sole();
            $this->assertSame(500, $delivery->http_status);
            $this->assertFalse($delivery->successful);
        }
    }

    public function test_a_connection_failure_is_recorded_and_rethrown(): void
    {
        Http::fake(function () {
            throw new ConnectionException('simulated timeout');
        });

        $merchant = Merchant::factory()->create(['webhook_url' => 'http://8.8.8.8/hook']);
        $payment = Payment::factory()->for($merchant)->paid()->create();
        $event = $this->eventFor($payment);

        $this->expectException(ConnectionException::class);

        try {
            (new DeliverMerchantWebhookJob($event->id))->handle(app(UrlSafetyChecker::class));
        } finally {
            $delivery = WebhookDelivery::sole();
            $this->assertNull($delivery->http_status);
            $this->assertFalse($delivery->successful);
        }
    }

    public function test_no_delivery_is_attempted_when_no_url_is_configured(): void
    {
        Http::fake();

        $merchant = Merchant::factory()->create(['webhook_url' => null]);
        $payment = Payment::factory()->for($merchant)->paid()->create(['callback_url' => null]);
        $event = $this->eventFor($payment);

        (new DeliverMerchantWebhookJob($event->id))->handle(app(UrlSafetyChecker::class));

        Http::assertNothingSent();
        $this->assertSame(0, WebhookDelivery::count());
    }

    public function test_payment_callback_url_overrides_the_merchants_default_webhook_url(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        $merchant = Merchant::factory()->create(['webhook_url' => 'http://8.8.8.8/merchant-default']);
        $payment = Payment::factory()->for($merchant)->paid()->create(['callback_url' => 'http://1.1.1.1/payment-specific']);
        $event = $this->eventFor($payment);

        (new DeliverMerchantWebhookJob($event->id))->handle(app(UrlSafetyChecker::class));

        Http::assertSent(fn ($request) => $request->url() === 'http://1.1.1.1/payment-specific');
    }

    public function test_an_unsafe_url_is_refused_without_a_retry(): void
    {
        Http::fake();
        config(['webhooks.allow_private_urls' => false]);

        $merchant = Merchant::factory()->create(['webhook_url' => 'http://127.0.0.1/hook']);
        $payment = Payment::factory()->for($merchant)->paid()->create();
        $event = $this->eventFor($payment);

        // Does not throw - fail() outside a real queue dispatch context is a safe
        // no-op beyond marking intent, so this call returns normally.
        (new DeliverMerchantWebhookJob($event->id))->handle(app(UrlSafetyChecker::class));

        Http::assertNothingSent();
        $delivery = WebhookDelivery::sole();
        $this->assertFalse($delivery->successful);
        $this->assertNull($delivery->http_status);
    }
}
