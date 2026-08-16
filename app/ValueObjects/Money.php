<?php

namespace App\ValueObjects;

use InvalidArgumentException;

/**
 * Money as an integer amount in the currency's smallest unit (e.g. cents/haléře) plus
 * an ISO 4217 currency code - never a float. Floats can't represent most decimal
 * fractions exactly in binary, so repeated arithmetic (sum of refunds, etc.) silently
 * drifts. Integers in minor units have no such rounding error.
 *
 * `readonly` (PHP 8.1+): once constructed, a Money instance can't be mutated in place.
 * add()/subtract() return a *new* instance instead. This matters here specifically -
 * without it, passing a Money into a function and having it mutated out from under the
 * caller would be exactly the kind of bug you do not want in payment math.
 */
final readonly class Money
{
    public function __construct(
        public int $amount,
        public string $currency,
    ) {
        if ($amount < 0) {
            throw new InvalidArgumentException('Money amount cannot be negative.');
        }

        if (! preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new InvalidArgumentException('Currency must be a 3-letter uppercase ISO 4217 code.');
        }
    }

    public function add(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amount + $other->amount, $this->currency);
    }

    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amount - $other->amount, $this->currency);
    }

    public function equals(self $other): bool
    {
        return $this->amount === $other->amount && $this->currency === $other->currency;
    }

    public function greaterThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->amount > $other->amount;
    }

    public function isZero(): bool
    {
        return $this->amount === 0;
    }

    /**
     * Adding/comparing CZK to EUR is a bug, not a valid operation - fail loudly instead
     * of silently producing a nonsense number.
     */
    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidArgumentException(
                "Cannot combine different currencies: {$this->currency} and {$other->currency}."
            );
        }
    }
}
