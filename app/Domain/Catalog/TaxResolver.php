<?php

namespace App\Domain\Catalog;

use App\Models\Product;
use App\Models\TaxCategory;
use App\Models\TaxRate;

/**
 * Effective-dated tax lookup. A product's tax category (or the company
 * default category) points to rates with start and end dates, so a rate
 * change takes effect on its date without touching past sales. No rate is
 * hard-coded; a category without a rate means 0%.
 */
final class TaxResolver
{
    /** @var array<string, array{rate: string, inclusive: bool}> */
    private array $cache = [];

    /** @return array{rate: string, inclusive: bool} */
    public function for(Product $product, string $date): array
    {
        $categoryId = $product->tax_category_id ?? $this->defaultCategoryId();
        if ($categoryId === null) {
            return ['rate' => '0.0000', 'inclusive' => true];
        }
        $key = $categoryId.'|'.$date;
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }
        $rate = TaxRate::query()
            ->where('tax_category_id', $categoryId)
            ->where('effective_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $date))
            ->orderByDesc('effective_from')
            ->first();

        return $this->cache[$key] = $rate
            ? ['rate' => $rate->rate, 'inclusive' => (bool) $rate->is_inclusive]
            : ['rate' => '0.0000', 'inclusive' => true];
    }

    private function defaultCategoryId(): ?int
    {
        return TaxCategory::query()->where('is_default', true)->value('id');
    }
}
