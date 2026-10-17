<?php

use App\Support\Decimal;

it('rounds money half up without floating point error', function () {
    expect(Decimal::money('0.005'))->toBe('0.01')
        ->and(Decimal::money('2.675'))->toBe('2.68')
        ->and(Decimal::add('0.1', '0.2'))->toBe('0.3')
        ->and(Decimal::percentOf('999.99', '12.5'))->toBe('125.00');
});

it('allocates an amount across weights without losing a paisa', function () {
    $parts = Decimal::allocate('10.00', ['a' => '1', 'b' => '1', 'c' => '1']);
    expect(Decimal::sum($parts))->toBe('10.00')
        ->and(array_values($parts))->toContain('3.34');
});

it('rounds to cash steps', function () {
    expect(Decimal::roundToStep('1234.40', 5))->toBe('1235.00')
        ->and(Decimal::roundToStep('1232.49', 5))->toBe('1230.00')
        ->and(Decimal::roundToStep('1234.40', 1))->toBe('1234.00')
        ->and(Decimal::roundToStep('1235.00', 10))->toBe('1240.00');
});

it('trims trailing zeros only after a decimal point', function () {
    expect(Decimal::trim('100'))->toBe('100')
        ->and(Decimal::trim('12.500'))->toBe('12.5')
        ->and(Decimal::trim('3.000'))->toBe('3');
});
