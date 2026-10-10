<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Money\MoneyAmount;
use InvalidArgumentException;
use OverflowException;
use PHPUnit\Framework\TestCase;

final class MoneyAmountTest extends TestCase
{
    public function test_it_adds_minor_units_without_float_arithmetic(): void
    {
        $total = MoneyAmount::fromMinorUnits(250_000, 'NGN')
            ->add(MoneyAmount::fromMinorUnits(125_000, 'NGN'));

        self::assertSame(375_000, $total->minorUnits);
        self::assertSame('NGN', $total->currency);
    }

    public function test_it_subtracts_minor_units(): void
    {
        $remaining = MoneyAmount::fromMinorUnits(500_000, 'NGN')
            ->subtract(MoneyAmount::fromMinorUnits(125_000, 'NGN'));

        self::assertSame(375_000, $remaining->minorUnits);
    }

    public function test_it_rejects_mixing_ngn_and_ghs(): void
    {
        $this->expectException(InvalidArgumentException::class);

        MoneyAmount::fromMinorUnits(5_000, 'NGN')
            ->add(MoneyAmount::fromMinorUnits(5_000, 'GHS'));
    }

    public function test_it_rejects_invalid_currency_codes(): void
    {
        $this->expectException(InvalidArgumentException::class);

        MoneyAmount::fromMinorUnits(1_000, 'ngn');
    }

    public function test_it_compares_currency_and_minor_units(): void
    {
        self::assertTrue(
            MoneyAmount::fromMinorUnits(5_000, 'NGN')
                ->equals(MoneyAmount::fromMinorUnits(5_000, 'NGN'))
        );
        self::assertFalse(
            MoneyAmount::fromMinorUnits(5_000, 'NGN')
                ->equals(MoneyAmount::fromMinorUnits(5_000, 'GHS'))
        );
    }

    public function test_it_rejects_addition_overflow(): void
    {
        $this->expectException(OverflowException::class);

        MoneyAmount::fromMinorUnits(PHP_INT_MAX, 'NGN')
            ->add(MoneyAmount::fromMinorUnits(1, 'NGN'));
    }

    public function test_it_rejects_subtraction_overflow(): void
    {
        $this->expectException(OverflowException::class);

        MoneyAmount::fromMinorUnits(PHP_INT_MIN, 'NGN')
            ->subtract(MoneyAmount::fromMinorUnits(1, 'NGN'));
    }
}