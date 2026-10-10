<?php

declare(strict_types=1);

namespace App\Domain\Money;

use InvalidArgumentException;
use OverflowException;

final readonly class MoneyAmount
{
    private function __construct(
        public int $minorUnits,
        public string $currency,
    ) {
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new InvalidArgumentException('Currency must be a three-letter uppercase code.');
        }
    }

    public static function fromMinorUnits(int $minorUnits, string $currency): self
    {
        return new self($minorUnits, $currency);
    }

    public function add(self $other): self
    {
        $this->assertSameCurrency($other);

        if (
            ($other->minorUnits > 0 && $this->minorUnits > PHP_INT_MAX - $other->minorUnits)
            || ($other->minorUnits < 0 && $this->minorUnits < PHP_INT_MIN - $other->minorUnits)
        ) {
            throw new OverflowException('Money addition exceeds the supported integer range.');
        }

        return new self($this->minorUnits + $other->minorUnits, $this->currency);
    }

    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other);

        if (
            ($other->minorUnits < 0 && $this->minorUnits > PHP_INT_MAX + $other->minorUnits)
            || ($other->minorUnits > 0 && $this->minorUnits < PHP_INT_MIN + $other->minorUnits)
        ) {
            throw new OverflowException('Money subtraction exceeds the supported integer range.');
        }

        return new self($this->minorUnits - $other->minorUnits, $this->currency);
    }

    public function equals(self $other): bool
    {
        return $this->currency === $other->currency
            && $this->minorUnits === $other->minorUnits;
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidArgumentException('Cannot combine amounts with different currencies.');
        }
    }
}