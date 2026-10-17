<?php

namespace App\Domain\Sales;

use App\Models\ProductVariant;
use App\Models\VariantUnit;

/** A fully priced line ready to be recorded on an invoice. */
final class SaleLine
{
    /** @param array{type: string, value: string}|null $discount */
    public function __construct(
        public readonly ProductVariant $variant,
        public readonly VariantUnit $unit,
        public readonly string $quantity,
        public readonly string $baseQuantity,
        public readonly string $listPrice,
        public readonly string $unitPrice,
        public readonly string $taxRate,
        public readonly bool $taxInclusive,
        public readonly ?array $discount = null,
        public readonly ?int $discountReasonId = null,
        public readonly ?int $approvalId = null,
        public readonly ?int $salesOrderLineId = null,
        public readonly ?string $description = null, // typed name of an unlisted item
    ) {
    }

    public function priceOverridden(): bool
    {
        return \App\Support\Decimal::compare($this->listPrice, $this->unitPrice) !== 0;
    }

    /** @return array{key: int|string, unit_price: string, quantity: string, discount: array{type: string, value: string}|null, tax_rate: string, tax_inclusive: bool} */
    public function forPricer(int|string $key): array
    {
        return [
            'key' => $key,
            'unit_price' => $this->unitPrice,
            'quantity' => $this->quantity,
            'discount' => $this->discount,
            'tax_rate' => $this->taxRate,
            'tax_inclusive' => $this->taxInclusive,
        ];
    }
}
