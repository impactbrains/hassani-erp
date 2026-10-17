<?php

namespace App\Domain\Catalog;

use App\Domain\Inventory\Availability;
use App\Models\ProductVariant;
use App\Models\VariantUnit;
use Illuminate\Database\Eloquent\Builder;

/**
 * Product search for the POS, order entry and the e-commerce API: one shape
 * with every sellable pack, its current price for the customer's price book
 * and the stock available to sell in the store. Searches English and Urdu
 * names, SKU and barcode (trigram index on names).
 */
final class CatalogSearch
{
    public function __construct(private readonly PriceResolver $prices, private readonly Availability $availability, private readonly BarcodeLookup $barcodes)
    {
    }

    /** @return list<array<string, mixed>> */
    public function search(?string $term, int $storeId, ?int $priceBookId = null, int $limit = 20, ?int $categoryId = null, bool $onlineOnly = false, string $purpose = 'sale'): array
    {
        $term = trim((string) $term);
        if ($term !== '' && ($hit = $this->barcodes->find($term))) {
            return [$this->card($hit['variant'], $storeId, $priceBookId, $hit['unit']->id, $this->availability->availableMany($storeId, [$hit['variant']->id]), $purpose)];
        }
        $query = ProductVariant::query()->with(['product.category', 'units.unit', 'barcodes'])
            ->where('product_variants.is_active', true)
            ->whereHas('product', fn (Builder $q) => $q->where('is_active', true)->where('is_open_item', false)->when($onlineOnly, fn ($o) => $o->where('is_online', true))->when($categoryId, fn ($c) => $c->where('category_id', $categoryId)));
        if ($term !== '') {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';
            $query->where(fn (Builder $q) => $q->where('product_variants.sku', 'ilike', $like)->orWhere('product_variants.name', 'ilike', $like)->orWhere('product_variants.name_ur', 'ilike', $like)
                ->orWhereHas('product', fn ($p) => $p->where('name', 'ilike', $like)->orWhere('name_ur', 'ilike', $like)->orWhere('manufacturer', 'ilike', $like))
                ->orWhereHas('barcodes', fn ($b) => $b->where('code', 'like', $term.'%')));
        }
        $variants = $query->orderBy('product_variants.name')->limit($limit)->get();
        $stock = $this->availability->availableMany($storeId, $variants->pluck('id')->all());

        return $variants->map(fn (ProductVariant $v) => $this->card($v, $storeId, $priceBookId, null, $stock, $purpose))->all();
    }

    /**
     * @param  array<int, string>  $stock
     * @param  string  $purpose  sale (sellable packs), purchase (buyable packs) or any
     * @return array<string, mixed>
     */
    public function card(ProductVariant $variant, int $storeId, ?int $priceBookId, ?int $scannedUnitId, array $stock, string $purpose = 'sale'): array
    {
        $variant->loadMissing(['product', 'units.unit', 'barcodes']);
        $units = $variant->units->filter(fn (VariantUnit $u) => match ($purpose) { 'purchase' => $u->can_purchase, 'any' => true, default => $u->can_sell })->sortBy('factor')->values();

        return [
            'variant_id' => $variant->id,
            'product_id' => $variant->product_id,
            'sku' => $variant->sku,
            'name' => $variant->displayName(),
            'name_ur' => $variant->displayNameUr(),
            'category' => $variant->product->category?->name,
            'mrp' => $variant->mrp,
            'is_loose' => (bool) $variant->is_loose,
            'type' => $variant->product->type,
            'track_batches' => (bool) $variant->product->track_batches,
            'available' => $stock[$variant->id] ?? '0.000',
            'scanned_unit_id' => $scannedUnitId,
            'units' => $units->map(fn (VariantUnit $u) => [
                'id' => $u->id,
                'code' => $u->unit?->code,
                'name' => $u->unit?->name,
                'name_ur' => $u->unit?->name_ur,
                'factor' => $u->factor,
                'allows_fraction' => (bool) $u->unit?->allows_fraction,
                'is_default' => (bool) ($purpose === 'purchase' ? $u->is_default_purchase : $u->is_default_sale),
                'is_base' => (bool) $u->is_base,
                'price' => $this->prices->resolve($variant, $u, $priceBookId)['price'] ?? null,
            ])->all(),
            'barcodes' => $variant->barcodes->pluck('code')->all(),
        ];
    }
}
