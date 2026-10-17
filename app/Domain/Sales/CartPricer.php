<?php

namespace App\Domain\Sales;

use App\Exceptions\BusinessRuleException;
use App\Support\Decimal;

/**
 * Pure pricing arithmetic shared by the POS, sales orders, invoices and the
 * e-commerce API so a total can never mean different things in different
 * places.
 *
 * For each line:
 *   gross        = unit price x quantity
 *   net          = gross - line discount - share of the bill discount
 *   tax          = net x rate / (100 + rate)   when prices include tax
 *                = net x rate / 100            when tax is added on top
 *   revenue      = net excluding tax
 *   line total   = what the customer pays for the line
 * Bill total     = sum of line totals, then rounded to the configured cash
 *                  step; the rounding adjustment is kept separate.
 */
final class CartPricer
{
    /**
     * @param  list<array{key?: int|string, unit_price: string, quantity: string, discount?: array{type: string, value: string}|null, tax_rate: string, tax_inclusive: bool}>  $lines
     * @param  array{type: string, value: string}|null  $cartDiscount
     * @return array{lines: list<array<string, string>>, subtotal: string, line_discount_total: string, cart_discount: string, discount_total: string, tax_total: string, total_before_rounding: string, rounding_adjustment: string, total: string, max_discount_percent: string}
     */
    public function price(array $lines, ?array $cartDiscount = null, string|int $roundingStep = 0): array
    {
        $priced = [];
        $maxPercent = '0';
        foreach ($lines as $index => $line) {
            $gross = Decimal::money(Decimal::mul($line['unit_price'], $line['quantity']));
            $lineDiscount = $this->discountAmount($line['discount'] ?? null, $gross);
            if (Decimal::isPositive($gross)) {
                $maxPercent = Decimal::max($maxPercent, Decimal::div(Decimal::mul($lineDiscount, '100'), $gross, 4));
            }
            $priced[$index] = ['gross' => $gross, 'line_discount' => $lineDiscount];
        }

        $afterLineDiscounts = array_map(fn ($p) => Decimal::sub($p['gross'], $p['line_discount']), $priced);
        $base = Decimal::money(Decimal::sum($afterLineDiscounts));
        $cartDiscountAmount = $this->discountAmount($cartDiscount, $base);
        if (Decimal::isPositive($base) && Decimal::isPositive($cartDiscountAmount)) {
            $maxPercent = Decimal::max($maxPercent, Decimal::div(Decimal::mul($cartDiscountAmount, '100'), $base, 4));
        }
        $shares = Decimal::allocate($cartDiscountAmount, $afterLineDiscounts);

        $result = [];
        foreach ($lines as $index => $line) {
            $net = Decimal::money(Decimal::sub($afterLineDiscounts[$index], $shares[$index] ?? '0'));
            $rate = $line['tax_rate'] ?? '0';
            if ($line['tax_inclusive'] ?? true) {
                $tax = Decimal::isZero($rate) ? '0.00' : Decimal::money(Decimal::div(Decimal::mul($net, $rate), Decimal::add('100', $rate), 10));
                $revenue = Decimal::money(Decimal::sub($net, $tax));
                $lineTotal = $net;
            } else {
                $tax = Decimal::percentOf($net, $rate);
                $revenue = $net;
                $lineTotal = Decimal::money(Decimal::add($net, $tax));
            }
            $result[] = [
                'key' => (string) ($line['key'] ?? $index),
                'gross' => $priced[$index]['gross'],
                'line_discount' => $priced[$index]['line_discount'],
                'cart_discount_share' => Decimal::money($shares[$index] ?? '0'),
                'net' => $net,
                'tax_amount' => $tax,
                'revenue_amount' => $revenue,
                'line_total' => $lineTotal,
            ];
        }

        $subtotal = Decimal::money(Decimal::sum(array_column($result, 'gross')));
        $lineDiscountTotal = Decimal::money(Decimal::sum(array_column($result, 'line_discount')));
        $taxTotal = Decimal::money(Decimal::sum(array_column($result, 'tax_amount')));
        $beforeRounding = Decimal::money(Decimal::sum(array_column($result, 'line_total')));
        $rounded = (int) $roundingStep > 0 ? Decimal::roundToStep($beforeRounding, (int) $roundingStep) : $beforeRounding;

        return [
            'lines' => $result,
            'subtotal' => $subtotal,
            'line_discount_total' => $lineDiscountTotal,
            'cart_discount' => Decimal::money($cartDiscountAmount),
            'discount_total' => Decimal::money(Decimal::add($lineDiscountTotal, $cartDiscountAmount)),
            'tax_total' => $taxTotal,
            'total_before_rounding' => $beforeRounding,
            'rounding_adjustment' => Decimal::money(Decimal::sub($rounded, $beforeRounding)),
            'total' => $rounded,
            'max_discount_percent' => Decimal::round($maxPercent, 2),
        ];
    }

    /** @param array{type: string, value: string}|null $discount */
    private function discountAmount(?array $discount, string $base): string
    {
        if ($discount === null || ! isset($discount['value']) || $discount['value'] === '' || Decimal::isZero($discount['value'])) {
            return '0.00';
        }
        if (Decimal::isNegative($discount['value'])) {
            throw new BusinessRuleException('A discount cannot be negative.', 'invalid_discount', field: 'discount');
        }
        $amount = $discount['type'] === 'percent'
            ? Decimal::percentOf($base, $discount['value'])
            : Decimal::money($discount['value']);
        if (Decimal::gt($amount, $base)) {
            throw new BusinessRuleException('A discount cannot be more than the amount it applies to.', 'discount_too_large', field: 'discount');
        }

        return $amount;
    }
}
