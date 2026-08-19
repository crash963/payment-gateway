<?php

namespace Tests\Feature\Copilot;

use App\Enums\PaymentEventType;
use App\Jobs\DeliverMerchantWebhookJob;
use App\Models\Merchant;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\WebhookDelivery;
use App\Services\Copilot\Tools\GetPaymentEventsTool;
use App\Services\Copilot\Tools\GetWebhookDeliveriesTool;
use App\Services\Copilot\Tools\ResendWebhookTool;
use App\Services\Copilot\Tools\SearchDocumentationTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ToolsTest extends TestCase
{
    use RefreshDatabase;

    public function test_get_payment_events_returns_the_events_for_the_merchants_own_payment(): void
    {
        $merchant = Merchant::factory()->create();
        $payment = Payment::factory()->for($merchant)->paid()->create();
        PaymentEvent::factory()->for($payment)->create(['type' => PaymentEventType::PaymentCreated]);

        $result = (new GetPaymentEventsTool)->execute($merchant, ['payment_id' => $payment->id]);

        $this->assertCount(1, $result['events']);
    }

    public function test_get_payment_events_refuses_another_merchants_payment(): void
    {
        $owner = Merchant::factory()->create();
        $requester = Merchant::factory()->create();
        $payment = Payment::factory()->for($owner)->paid()->create();
        PaymentEvent::factory()->for($payment)->create();

        $result = (new GetPaymentEventsTool)->execute($requester, ['payment_id' => $payment->id]);

        $this->assertArrayHasKey('error', $result);
    }

    public function test_get_webhook_deliveries_scopes_to_the_merchants_own_payment(): void
    {
        $merchant = Merchant::factory()->create();
        $payment = Payment::factory()->for($merchant)->paid()->create();
        $event = PaymentEvent::factory()->for($payment)->create();
        WebhookDelivery::factory()->for($event, 'paymentEvent')->for($merchant)->create();

        $result = (new GetWebhookDeliveriesTool)->execute($merchant, ['payment_id' => $payment->id]);

        $this->assertCount(1, $result['deliveries']);
        $this->assertArrayHasKey('webhook_delivery_id', $result['deliveries'][0]);
    }

    public function test_resend_webhook_without_confirmation_only_describes_the_action(): void
    {
        Queue::fake();
        $merchant = Merchant::factory()->create();
        $payment = Payment::factory()->for($merchant)->paid()->create();
        $event = PaymentEvent::factory()->for($payment)->create();
        $delivery = WebhookDelivery::factory()->for($event, 'paymentEvent')->for($merchant)->create();

        $result = (new ResendWebhookTool)->execute($merchant, ['webhook_delivery_id' => $delivery->id]);

        $this->assertTrue($result['requires_confirmation']);
        Queue::assertNotPushed(DeliverMerchantWebhookJob::class);
    }

    public function test_resend_webhook_with_confirmation_dispatches_the_delivery_job(): void
    {
        Queue::fake();
        $merchant = Merchant::factory()->create();
        $payment = Payment::factory()->for($merchant)->paid()->create();
        $event = PaymentEvent::factory()->for($payment)->create();
        $delivery = WebhookDelivery::factory()->for($event, 'paymentEvent')->for($merchant)->create();

        $result = (new ResendWebhookTool)->execute($merchant, [
            'webhook_delivery_id' => $delivery->id,
            'confirmed' => true,
        ]);

        $this->assertSame('resend_dispatched', $result['status']);
        Queue::assertPushed(DeliverMerchantWebhookJob::class, fn ($job) => $job->paymentEventId === $event->id);
    }

    public function test_resend_webhook_refuses_another_merchants_delivery(): void
    {
        $owner = Merchant::factory()->create();
        $requester = Merchant::factory()->create();
        $payment = Payment::factory()->for($owner)->paid()->create();
        $event = PaymentEvent::factory()->for($payment)->create();
        $delivery = WebhookDelivery::factory()->for($event, 'paymentEvent')->for($owner)->create();

        $result = (new ResendWebhookTool)->execute($requester, [
            'webhook_delivery_id' => $delivery->id,
            'confirmed' => true,
        ]);

        $this->assertArrayHasKey('error', $result);
    }

    public function test_search_documentation_finds_a_known_term(): void
    {
        $merchant = Merchant::factory()->create();

        $result = (new SearchDocumentationTool)->execute($merchant, ['query' => 'idempotency']);

        $this->assertNotEmpty($result['results']);
    }

    /**
     * Regression test (code review): `! stripos($content, $query)` treated a match at
     * position 0 (the very start of a file) as "no match", since stripos() returns int
     * 0 there and `!0` is true. openapi.yaml's content literally starts with "openapi:
     * 3.0.3" - searching for "openapi" matches at position 0 and used to be silently
     * skipped, even though it's the strongest possible match.
     */
    public function test_search_documentation_finds_a_match_at_the_very_start_of_a_file(): void
    {
        $merchant = Merchant::factory()->create();

        $result = (new SearchDocumentationTool)->execute($merchant, ['query' => 'openapi']);

        $this->assertNotEmpty($result['results']);
        $this->assertTrue(collect($result['results'])->contains(fn ($r) => $r['file'] === 'openapi.yaml'));
    }

    public function test_search_documentation_reports_no_results_for_a_nonsense_query(): void
    {
        $merchant = Merchant::factory()->create();

        $result = (new SearchDocumentationTool)->execute($merchant, ['query' => 'xyzzy-nonexistent-term-qqq']);

        $this->assertSame([], $result['results']);
    }
}
