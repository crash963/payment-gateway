<?php

namespace Tests\Unit;

use App\ValueObjects\Money;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_negative_amount_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Money(-100, 'CZK');
    }

    public function test_currency_must_be_a_three_letter_uppercase_code(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Money(100, 'czk');
    }

    public function test_add_sums_amounts_in_the_same_currency(): void
    {
        $result = (new Money(1000, 'CZK'))->add(new Money(500, 'CZK'));

        $this->assertSame(1500, $result->amount);
        $this->assertSame('CZK', $result->currency);
    }

    public function test_add_is_immutable(): void
    {
        // The whole point of `readonly` + returning a new instance: the original must
        // be untouched by an operation performed on it.
        $original = new Money(1000, 'CZK');
        $original->add(new Money(500, 'CZK'));

        $this->assertSame(1000, $original->amount);
    }

    public function test_cannot_add_different_currencies(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new Money(1000, 'CZK'))->add(new Money(500, 'EUR'));
    }

    public function test_subtract_reduces_amount(): void
    {
        $result = (new Money(1000, 'CZK'))->subtract(new Money(300, 'CZK'));

        $this->assertSame(700, $result->amount);
    }

    public function test_equals_compares_amount_and_currency(): void
    {
        $this->assertTrue((new Money(1000, 'CZK'))->equals(new Money(1000, 'CZK')));
        $this->assertFalse((new Money(1000, 'CZK'))->equals(new Money(1000, 'EUR')));
        $this->assertFalse((new Money(1000, 'CZK'))->equals(new Money(999, 'CZK')));
    }

    public function test_greater_than(): void
    {
        $this->assertTrue((new Money(1000, 'CZK'))->greaterThan(new Money(999, 'CZK')));
        $this->assertFalse((new Money(1000, 'CZK'))->greaterThan(new Money(1000, 'CZK')));
    }

    public function test_is_zero(): void
    {
        $this->assertTrue((new Money(0, 'CZK'))->isZero());
        $this->assertFalse((new Money(1, 'CZK'))->isZero());
    }
}
