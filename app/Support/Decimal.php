<?php

namespace App\Support;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Exact decimal arithmetic for money, quantities and costs.
 *
 * Values travel as numeric strings (the form PostgreSQL returns for numeric
 * columns) and are never converted to binary floats. Rounding is always
 * HALF_UP, the convention used on Pakistani retail receipts.
 */
final class Decimal
{
    public const MONEY_SCALE = 2;

    public const QTY_SCALE = 3;

    public const COST_SCALE = 6;

    public static function of(string|int|float|BigDecimal|null $value): BigDecimal
    {
        if ($value === null || $value === '') {
            return BigDecimal::zero();
        }
        if ($value instanceof BigDecimal) {
            return $value;
        }
        if (is_float($value)) {
            // Floats only arrive from loosely typed input; go through their
            // shortest exact string representation instead of the binary value.
            $value = rtrim(rtrim(sprintf('%.10F', $value), '0'), '.');
        }

        return BigDecimal::of((string) $value);
    }

    public static function money(string|int|float|BigDecimal|null $value): string
    {
        return (string) self::of($value)->toScale(self::MONEY_SCALE, RoundingMode::HALF_UP);
    }

    public static function qty(string|int|float|BigDecimal|null $value): string
    {
        return (string) self::of($value)->toScale(self::QTY_SCALE, RoundingMode::HALF_UP);
    }

    public static function cost(string|int|float|BigDecimal|null $value): string
    {
        return (string) self::of($value)->toScale(self::COST_SCALE, RoundingMode::HALF_UP);
    }

    /** Human-friendly form without trailing zeros: "12.500" -> "12.5", "100" -> "100". */
    public static function trim(string|int|BigDecimal|null $value): string
    {
        $string = (string) self::of($value);
        if (! str_contains($string, '.')) {
            return $string;
        }

        return rtrim(rtrim($string, '0'), '.');
    }

    public static function round(string|int|BigDecimal $value, int $scale): string
    {
        return (string) self::of($value)->toScale($scale, RoundingMode::HALF_UP);
    }

    public static function add(string|int|BigDecimal|null ...$values): string
    {
        $sum = BigDecimal::zero();
        foreach ($values as $value) {
            $sum = $sum->plus(self::of($value));
        }

        return (string) $sum;
    }

    public static function sub(string|int|BigDecimal|null $a, string|int|BigDecimal|null $b): string
    {
        return (string) self::of($a)->minus(self::of($b));
    }

    public static function mul(string|int|BigDecimal|null $a, string|int|BigDecimal|null $b): string
    {
        return (string) self::of($a)->multipliedBy(self::of($b));
    }

    public static function div(string|int|BigDecimal|null $a, string|int|BigDecimal|null $b, int $scale = 6): string
    {
        $divisor = self::of($b);
        if ($divisor->isZero()) {
            throw new \DivisionByZeroError('Decimal division by zero');
        }

        return (string) self::of($a)->dividedBy($divisor, $scale, RoundingMode::HALF_UP);
    }

    public static function neg(string|int|BigDecimal|null $value): string
    {
        return (string) self::of($value)->negated();
    }

    public static function abs(string|int|BigDecimal|null $value): string
    {
        return (string) self::of($value)->abs();
    }

    /** @param iterable<string|int|BigDecimal|null> $values */
    public static function sum(iterable $values): string
    {
        $sum = BigDecimal::zero();
        foreach ($values as $value) {
            $sum = $sum->plus(self::of($value));
        }

        return (string) $sum;
    }

    public static function compare(string|int|BigDecimal|null $a, string|int|BigDecimal|null $b): int
    {
        return self::of($a)->compareTo(self::of($b));
    }

    public static function eq(string|int|BigDecimal|null $a, string|int|BigDecimal|null $b): bool
    {
        return self::compare($a, $b) === 0;
    }

    public static function gt(string|int|BigDecimal|null $a, string|int|BigDecimal|null $b): bool
    {
        return self::compare($a, $b) > 0;
    }

    public static function gte(string|int|BigDecimal|null $a, string|int|BigDecimal|null $b): bool
    {
        return self::compare($a, $b) >= 0;
    }

    public static function lt(string|int|BigDecimal|null $a, string|int|BigDecimal|null $b): bool
    {
        return self::compare($a, $b) < 0;
    }

    public static function lte(string|int|BigDecimal|null $a, string|int|BigDecimal|null $b): bool
    {
        return self::compare($a, $b) <= 0;
    }

    public static function isZero(string|int|BigDecimal|null $value): bool
    {
        return self::of($value)->isZero();
    }

    public static function isNegative(string|int|BigDecimal|null $value): bool
    {
        return self::of($value)->isNegative();
    }

    public static function isPositive(string|int|BigDecimal|null $value): bool
    {
        return self::of($value)->isPositive();
    }

    public static function min(string|int|BigDecimal|null $a, string|int|BigDecimal|null $b): string
    {
        return self::lte($a, $b) ? (string) self::of($a) : (string) self::of($b);
    }

    public static function max(string|int|BigDecimal|null $a, string|int|BigDecimal|null $b): string
    {
        return self::gte($a, $b) ? (string) self::of($a) : (string) self::of($b);
    }

    /** Percentage of an amount, rounded to money scale: percentOf("1000", "12.5") = "125.00". */
    public static function percentOf(string|int|BigDecimal|null $amount, string|int|BigDecimal|null $percent): string
    {
        return self::money(self::of($amount)->multipliedBy(self::of($percent))->dividedBy(100, 10, RoundingMode::HALF_UP));
    }

    /** Rounds a money amount to the nearest step (1, 5, 10 rupees ...). */
    public static function roundToStep(string|int|BigDecimal $amount, string|int $step): string
    {
        $stepDecimal = self::of($step);
        if ($stepDecimal->isZero()) {
            return self::money($amount);
        }
        $units = self::of($amount)->dividedBy($stepDecimal, 0, RoundingMode::HALF_UP);

        return self::money($units->multipliedBy($stepDecimal));
    }

    /**
     * Splits an amount across weights so the parts add up exactly to the
     * amount (largest-remainder method). Used to allocate cart discounts and
     * landed costs across lines without losing a paisa to rounding.
     *
     * @param  array<int|string, string>  $weights
     * @return array<int|string, string>
     */
    public static function allocate(string $amount, array $weights, int $scale = self::MONEY_SCALE): array
    {
        $total = self::of(self::sum($weights));
        if ($total->isZero() || $weights === []) {
            return array_map(fn () => self::round('0', $scale), $weights);
        }

        $unit = BigDecimal::one()->withPointMovedLeft($scale);
        $target = self::of($amount)->toScale($scale, RoundingMode::HALF_UP);
        $parts = [];
        $remainders = [];
        $allocated = BigDecimal::zero();
        foreach ($weights as $key => $weight) {
            $exact = $target->multipliedBy(self::of($weight))->dividedBy($total, $scale + 8, RoundingMode::DOWN);
            $floor = $exact->toScale($scale, RoundingMode::DOWN);
            $parts[$key] = $floor;
            $remainders[$key] = $exact->minus($floor);
            $allocated = $allocated->plus($floor);
        }

        $leftover = $target->minus($allocated);
        uasort($remainders, fn (BigDecimal $a, BigDecimal $b) => $b->compareTo($a));
        foreach (array_keys($remainders) as $key) {
            if ($leftover->isZero() || $leftover->isNegative()) {
                break;
            }
            $parts[$key] = $parts[$key]->plus($unit);
            $leftover = $leftover->minus($unit);
        }

        return array_map(fn (BigDecimal $part) => (string) $part->toScale($scale), $parts);
    }
}
