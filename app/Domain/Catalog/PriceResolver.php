<?php

namespace App\Domain\Catalog;

use App\Models\Price;
use App\Models\PriceBook;
use App\Models\ProductVariant;
use App\Models\VariantUnit;
use App\Support\Decimal;

/**
 * Finds the selling price of a SKU in a given pack unit.
 *
 * Order: the customer's price book, then the store's default book, then the
 * company default book. Within a book the price must be effective at the
 * sale time; wholesale tiers pick the highest minimum quantity reached.
 * There is deliberately no fallback that derives a carton price from a
 * piece price: a missing price blocks the sale until someone sets one.
 */
final class PriceResolver
{
    /** @var array<int, ?int> */
    private array $defaultBook = [];

    /** @return array{price: string, price_book_id: int}|null */
    public function resolve(ProductVariant|int $variant, VariantUnit|int $unit, ?int $priceBookId = null, string $quantity = '1', ?\DateTimeInterface $at = null): ?array
    {
        $variantId = $variant instanceof ProductVariant ? $variant->id : $variant;
        $unitId = $unit instanceof VariantUnit ? $unit->id : $unit;
        $at ??= now();

        foreach (array_unique(array_filter([$priceBookId, $this->defaultBookId()])) as $bookId) {
            $price = Price::query()
                ->where('price_book_id', $bookId)
                ->where('variant_id', $variantId)
                ->where('variant_unit_id', $unitId)
                ->where('effective_from', '<=', $at)
                ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', $at))
                ->where('min_quantity', '<=', Decimal::qty($quantity))
                ->orderByDesc('min_quantity')
                ->orderByDesc('effective_from')
                ->orderByDesc('id')
                ->first();
            if ($price) {
                return ['price' => $price->price, 'price_book_id' => $bookId];
            }
        }

        return null;
    }

    public function defaultBookId(): ?int
    {
        $companyId = \App\Support\Tenancy\CurrentCompany::require();
        if (! array_key_exists($companyId, $this->defaultBook)) {
            $this->defaultBook[$companyId] = PriceBook::query()->where('is_default', true)->value('id');
        }

        return $this->defaultBook[$companyId];
    }
}
