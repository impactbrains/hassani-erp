<?php

namespace App\Domain\Inventory;

/**
 * One requested change to stock at a location, in base units.
 * Positive quantity = stock in, negative = stock out.
 */
final class StockChange
{
    public function __construct(
        public readonly int $storeId,
        public readonly int $locationId,
        public readonly int $variantId,
        public readonly string $quantity,
        public readonly string $type,
        public readonly ?int $batchId = null,
        public readonly ?string $unitCost = null,
        public readonly ?int $reasonCodeId = null,
        public readonly ?int $sourceLineId = null,
        public readonly ?string $note = null,
    ) {
    }
}
