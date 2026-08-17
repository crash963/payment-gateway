<?php

namespace Tests\Unit;

use App\Enums\PaymentStatus;
use App\Enums\ProviderScenario;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ProviderScenarioTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: ProviderScenario}>
     */
    public static function orderIdPrefixes(): array
    {
        return [
            'DECLINE- prefix' => ['DECLINE-order-1', ProviderScenario::Declined],
            'TIMEOUT- prefix' => ['TIMEOUT-order-1', ProviderScenario::Timeout],
            'SLOW- prefix' => ['SLOW-order-1', ProviderScenario::SlowResponse],
            'DUPLICATE- prefix' => ['DUPLICATE-order-1', ProviderScenario::DuplicateCallback],
            'INVALID- prefix' => ['INVALID-order-1', ProviderScenario::InvalidCallback],
            'no special prefix' => ['order-1', ProviderScenario::Success],
            'unrelated word containing DECLINE' => ['my-DECLINE-order', ProviderScenario::Success],
        ];
    }

    #[DataProvider('orderIdPrefixes')]
    public function test_scenario_is_selected_from_the_order_id_prefix(string $orderId, ProviderScenario $expected): void
    {
        $this->assertSame($expected, ProviderScenario::fromOrderId($orderId));
    }

    public function test_only_declined_resolves_to_a_failed_payment(): void
    {
        $this->assertSame(PaymentStatus::Failed, ProviderScenario::Declined->outcome());

        $this->assertSame(PaymentStatus::Paid, ProviderScenario::Success->outcome());
        $this->assertSame(PaymentStatus::Paid, ProviderScenario::Timeout->outcome());
        $this->assertSame(PaymentStatus::Paid, ProviderScenario::SlowResponse->outcome());
        $this->assertSame(PaymentStatus::Paid, ProviderScenario::DuplicateCallback->outcome());
        $this->assertSame(PaymentStatus::Paid, ProviderScenario::InvalidCallback->outcome());
    }
}
