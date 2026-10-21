<?php

namespace App\Domain\Catalog;

use App\Domain\Inventory\Availability;
use App\Models\ProductVariant;
use App\Models\VariantCost;
use App\Models\VariantUnit;
use App\Support\Decimal;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Product search for the POS, order entry and the e-commerce API: one shape
 * with every sellable pack, its current price for the customer's price book
 * and the stock available to sell in the store. Searches English and Urdu
 * names, SKU and barcode (trigram index on names).
 */
final class CatalogSearch
{
    public function __construct(private readonly PriceResolver $prices, private readonly Availability $availability, private readonly BarcodeLookup $barcodes, private readonly PriceRules $rules)
    {
    }

    /**
     * @param  array{supplier_id?: int|null}|null  $costs  when given (buying screens only), each card also carries what the item cost last time
     * @param  PriceContext|null  $pricing  when given (POS and staff screens), prices include price rules for one item; the website API passes none
     * @return list<array<string, mixed>>
     */
    public function search(?string $term, int $storeId, ?int $priceBookId = null, int $limit = 20, ?int $categoryId = null, bool $onlineOnly = false, string $purpose = 'sale', ?array $costs = null, ?PriceContext $pricing = null): array
    {
        $term = trim((string) $term);
        if ($term !== '' && ($hit = $this->barcodes->find($term))) {
            return [$this->withCosts($this->card($hit['variant'], $storeId, $priceBookId, $hit['unit']->id, $this->availability->availableMany($storeId, [$hit['variant']->id]), $purpose, $pricing), $storeId, $costs)];
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

        return $variants->map(fn (ProductVariant $v) => $this->withCosts($this->card($v, $storeId, $priceBookId, null, $stock, $purpose, $pricing), $storeId, $costs))->all();
    }

    /**
     * The selling price of one pack: its price list price, lowered by a price rule when one applies to a single item.
     *
     * @return array{price: string|null, price_tag: array<string, mixed>|null}
     */
    private function unitPrice(ProductVariant $variant, VariantUnit $unit, ?int $priceBookId, ?PriceContext $pricing): array
    {
        $baseline = $this->prices->resolve($variant, $unit, $priceBookId)['price'] ?? null;
        if ($pricing === null || $baseline === null) {
            return ['price' => $baseline, 'price_tag' => null];
        }
        $ruled = $this->rules->single($variant, $unit, $baseline, $pricing);

        return ['price' => $ruled['price'], 'price_tag' => $ruled['tag']];
    }

    /**
     * Adds what the item cost before, for the buying screens: the product's Purchase price, the last price paid
     * (from this supplier when there is one, otherwise from anyone) and the store's average cost. Prices are per base unit.
     *
     * @param  array<string, mixed>  $card
     * @param  array{supplier_id?: int|null}|null  $costs
     * @return array<string, mixed>
     */
    private function withCosts(array $card, int $storeId, ?array $costs): array
    {
        if ($costs === null) {
            return $card;
        }
        $last = fn (?int $supplierId) => DB::table('goods_receipt_lines as l')
            ->join('goods_receipts as r', 'r.id', '=', 'l.goods_receipt_id')
            ->leftJoin('contacts as c', 'c.id', '=', 'r.supplier_id')
            ->leftJoin('variant_units as vu', 'vu.id', '=', 'l.variant_unit_id')
            ->leftJoin('units as u', 'u.id', '=', 'vu.unit_id')
            ->where('r.company_id', CurrentCompany::require())->where('r.status', 'posted')->where('l.variant_id', $card['variant_id'])
            ->when($supplierId, fn ($q) => $q->where('r.supplier_id', $supplierId))
            ->orderByDesc('r.business_date')->orderByDesc('l.id')
            ->first(['l.unit_price', 'l.unit_factor', 'r.business_date', 'r.supplier_id', 'c.name as supplier', 'u.code as unit']);
        $supplierId = $costs['supplier_id'] ?? null;
        $row = ($supplierId ? $last($supplierId) : null) ?? $last(null);
        $card['last_cost'] = $row === null ? null : [
            'per_unit' => Decimal::money(Decimal::div($row->unit_price, $row->unit_factor, 6)),
            'pack_price' => Decimal::money($row->unit_price),
            'unit' => $row->unit,
            'date' => (string) $row->business_date,
            'supplier' => $row->supplier,
            'same_supplier' => $supplierId !== null && (int) $row->supplier_id === (int) $supplierId,
        ];
        $card['purchase_price'] = ($pp = ProductVariant::query()->whereKey($card['variant_id'])->value('purchase_price')) !== null ? Decimal::money((string) $pp) : null;
        $average = VariantCost::query()->where('store_id', $storeId)->where('variant_id', $card['variant_id'])->value('average_cost');
        $card['average_cost'] = $average !== null && Decimal::isPositive((string) $average) ? Decimal::money((string) $average) : null;

        return $card;
    }

    /**
     * @param  array<int, string>  $stock
     * @param  string  $purpose  sale (sellable packs), purchase (buyable packs) or any
     * @return array<string, mixed>
     */
    public function card(ProductVariant $variant, int $storeId, ?int $priceBookId, ?int $scannedUnitId, array $stock, string $purpose = 'sale', ?PriceContext $pricing = null): array
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
                ...$this->unitPrice($variant, $u, $priceBookId, $purpose === 'purchase' ? null : $pricing),
            ])->all(),
            'barcodes' => $variant->barcodes->pluck('code')->all(),
        ];
    }
}
