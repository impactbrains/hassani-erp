<?php

namespace App\Domain\Inventory;

/**
 * Movement types and how they affect store-level valuation.
 *
 * Relocations move stock between locations of the same store (put-away,
 * quarantine, damaged area) and do not change the store's inventory value.
 * Every other type changes both quantity and value.
 */
final class MovementTypes
{
    public const RECEIPT = 'receipt';

    public const SALE = 'sale';

    public const SALE_RETURN = 'sale_return';

    public const PURCHASE_RETURN = 'purchase_return';

    public const ADJUSTMENT = 'adjustment';

    public const WRITE_OFF = 'write_off';

    public const OPENING = 'opening';

    public const TRANSFER_OUT = 'transfer_out';

    public const TRANSFER_IN = 'transfer_in';

    public const RELOCATION = 'relocation';

    public const COUNT_VARIANCE = 'count_variance';

    public const REPACK_CONSUME = 'repack_consume';

    public const REPACK_PRODUCE = 'repack_produce';

    public const BUNDLE_CONSUME = 'bundle_consume';

    public const BUNDLE_RETURN = 'bundle_return';

    public static function affectsValuation(string $type): bool
    {
        return $type !== self::RELOCATION;
    }

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::RECEIPT, self::SALE, self::SALE_RETURN, self::PURCHASE_RETURN, self::ADJUSTMENT, self::WRITE_OFF,
            self::OPENING, self::TRANSFER_OUT, self::TRANSFER_IN, self::RELOCATION, self::COUNT_VARIANCE,
            self::REPACK_CONSUME, self::REPACK_PRODUCE, self::BUNDLE_CONSUME, self::BUNDLE_RETURN,
        ];
    }
}
