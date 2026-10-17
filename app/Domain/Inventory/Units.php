<?php

namespace App\Domain\Inventory;

use App\Exceptions\BusinessRuleException;
use App\Models\VariantUnit;
use App\Support\Decimal;

/** Converts between pack units and a SKU's base unit, refusing invalid quantities. */
final class Units
{
    /** Quantity in pack units -> quantity in base units. */
    public static function toBase(string $quantity, VariantUnit $variantUnit): string
    {
        self::assertValidQuantity($quantity, $variantUnit);

        return Decimal::qty(Decimal::mul($quantity, $variantUnit->factor));
    }

    public static function assertValidQuantity(string $quantity, VariantUnit $variantUnit): void
    {
        if (! is_numeric($quantity) || ! Decimal::isPositive($quantity)) {
            throw new BusinessRuleException('Quantity must be more than zero.', 'invalid_quantity', field: 'quantity');
        }
        $unit = $variantUnit->unit;
        $hasFraction = ! Decimal::eq(Decimal::round($quantity, 0), $quantity);
        if ($hasFraction && $unit !== null && ! $unit->allows_fraction) {
            throw new BusinessRuleException("{$unit->name} must be sold in whole numbers.", 'fraction_not_allowed', field: 'quantity');
        }
        if ($unit !== null && $unit->allows_fraction && ! Decimal::eq(Decimal::round($quantity, max(0, (int) $unit->decimals)), $quantity)) {
            throw new BusinessRuleException("{$unit->name} allows at most {$unit->decimals} decimal places.", 'too_many_decimals', field: 'quantity');
        }
    }
}
