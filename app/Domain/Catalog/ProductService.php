<?php

namespace App\Domain\Catalog;

use App\Exceptions\BusinessRuleException;
use App\Models\Barcode;
use App\Models\BundleComponent;
use App\Models\Price;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\VariantUnit;
use App\Support\Audit;
use App\Support\Decimal;
use App\Support\Sequences;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Creates and updates products with their SKUs, pack units, barcodes,
 * prices and bundle components in one transaction.
 *
 * Invariants: every SKU has exactly one base unit (factor 1); barcodes are
 * unique per company; a price change closes the old price and starts a new
 * effective-dated one (history is never overwritten). Deleting a product with
 * no history sends it to the Trash for 30 days; one with stock or sales
 * history is deactivated, never erased. SKUs and barcodes stay reserved while
 * a product is in the Trash or deactivated.
 */
final class ProductService
{
    /** @param array<string, mixed> $data */
    public function save(array $data, ?Product $product = null, bool $canChangePrices = true): Product
    {
        return DB::transaction(function () use ($data, $product, $canChangePrices) {
            $isNew = $product === null;
            $product ??= new Product;
            $before = $isNew ? [] : $product->only(['name', 'name_ur', 'type', 'is_active', 'tax_category_id']);
            $product->fill(collect($data)->only([
                'name', 'name_ur', 'category_id', 'brand_id', 'manufacturer', 'description', 'description_ur', 'type',
                'tax_category_id', 'track_batches', 'track_expiry', 'shelf_life_days', 'is_online', 'is_active', 'image_media_id',
            ])->all());
            if ($isNew) {
                $product->created_by = Auth::id();
            }
            $product->save();

            $keptVariantIds = [];
            foreach ($data['variants'] ?? [] as $index => $variantData) {
                $keptVariantIds[] = $this->saveVariant($product, $variantData, $index === 0, $canChangePrices)->id;
            }
            $this->retireMissingVariants($product, $keptVariantIds);

            Audit::record($isNew ? 'product.created' : 'product.updated', ($isNew ? 'Created' : 'Updated')." product {$product->name}", $product, $before, $product->only(array_keys($before ?: ['name' => 1])));

            return $product->fresh(['variants.units.unit', 'variants.barcodes', 'variants.prices']);
        });
    }

    /** Days a deleted product stays in the Trash before it is removed for good. */
    public const TRASH_DAYS = 30;

    /**
     * "Deletes" a product.
     *  - Never stocked, sold or bought: it goes to the Trash for 30 days, then is removed.
     *  - Has stock, sales, purchase or bundle history: it is kept for the books and
     *    moved to the Deactivated tab. It disappears from the POS either way.
     * SKUs and barcodes stay reserved in both cases, because the rows are kept.
     *
     * @return 'trashed'|'deactivated'
     */
    public function remove(Product $product): string
    {
        $name = $product->name;

        if ($this->hasHistory($product)) {
            DB::transaction(function () use ($product) {
                $product->variants()->update(['is_active' => false]);
                $product->update(['is_active' => false]);
            });
            Audit::record('product.deactivated', "Deactivated product {$name} (it has stock or sales history)", $product);

            return 'deactivated';
        }

        DB::transaction(function () use ($product) {
            $product->variants()->update(['is_active' => false]);
            $product->update(['is_active' => false, 'deleted_by' => Auth::id()]);
            $product->delete(); // soft delete: sets deleted_at
        });
        Audit::record('product.trashed', "Moved product {$name} to the Trash", $product);

        return 'trashed';
    }

    /** Brings a product back from the Trash or the Deactivated tab, active and sellable again. */
    public function restore(Product $product): Product
    {
        DB::transaction(function () use ($product) {
            if ($product->trashed()) {
                $product->restore();
            }
            $product->update(['is_active' => true, 'deleted_by' => null]);
            $product->variants()->update(['is_active' => true]);
        });
        Audit::record('product.restored', "Restored product {$product->name}", $product);

        return $product->refresh();
    }

    /**
     * Removes a trashed product for good. If history appeared since it was trashed
     * (it should not, because it cannot be sold), it moves to Deactivated instead.
     *
     * @return 'purged'|'deactivated'
     */
    public function purge(Product $product): string
    {
        $name = $product->name;
        try {
            DB::transaction(function () use ($product) {
                $product->variants()->delete(); // units, barcodes, prices cascade
                $product->forceDelete();
            });
        } catch (QueryException $e) {
            if (! in_array((string) $e->getCode(), ['23503', '23000'], true)) {
                throw $e;
            }
            $product->refresh();
            DB::transaction(function () use ($product) {
                $product->restore();
                $product->update(['is_active' => false]);
                $product->variants()->update(['is_active' => false]);
            });
            Audit::record('product.deactivated', "{$name} had history, so it was deactivated instead of removed", $product);

            return 'deactivated';
        }
        Audit::record('product.purged', "Permanently removed product {$name}", null);

        return 'purged';
    }

    /** Removes everything that has been in the Trash longer than TRASH_DAYS. Returns how many were removed. */
    public function purgeExpired(): int
    {
        $count = 0;
        Product::onlyTrashed()->where('deleted_at', '<', now()->subDays(self::TRASH_DAYS))->get()
            ->each(function (Product $product) use (&$count) {
                if ($this->purge($product) === 'purged') {
                    $count++;
                }
            });

        return $count;
    }

    /**
     * True when anything points at one of the product's SKUs: stock movements, invoices,
     * purchase lines, use inside a bundle ... The database's foreign keys decide, so every
     * table that references a variant is covered without listing them here. The probe
     * deletes inside a transaction and always rolls back.
     */
    public function hasHistory(Product $product): bool
    {
        try {
            DB::transaction(function () use ($product) {
                DB::table('product_variants')->where('product_id', $product->id)->delete();
                DB::table('products')->where('id', $product->id)->delete();
                DB::statement('SET CONSTRAINTS ALL IMMEDIATE'); // surface any deferred foreign-key check now
                throw new ProductDeleteProbe;
            });
        } catch (ProductDeleteProbe) {
            return false;
        } catch (QueryException $e) {
            if (! in_array((string) $e->getCode(), ['23503', '23000'], true)) {
                throw $e;
            }

            return true;
        }

        return false;
    }

    /** Where a reserved SKU or barcode lives, so the error can say what to do about it. */
    private function reservedNote(?ProductVariant $holder): string
    {
        if ($holder === null) {
            return '';
        }
        $product = Product::withTrashed()->find($holder->product_id);
        if ($product?->trashed()) {
            return ' It is in the Trash: restore it, or delete it permanently from the Trash, to use this again.';
        }
        if ($product && ! $product->is_active) {
            return ' It is deactivated: restore it from the Deactivated tab, or give this one a different code.';
        }

        return '';
    }

    /** @param array<string, mixed> $data */
    private function saveVariant(Product $product, array $data, bool $isFirst, bool $canChangePrices): ProductVariant
    {
        $variant = isset($data['id'])
            ? ProductVariant::query()->where('product_id', $product->id)->findOrFail($data['id'])
            : new ProductVariant(['product_id' => $product->id]);

        $sku = trim((string) ($data['sku'] ?? ''));
        if ($sku === '') {
            $sku = $variant->sku ?: Sequences::next('sku');
        }
        $skuHolder = ProductVariant::query()->where('sku', $sku)->when($variant->exists, fn ($q) => $q->whereKeyNot($variant->id))->first();
        if ($skuHolder) {
            throw new BusinessRuleException("SKU {$sku} is already used by another product.".$this->reservedNote($skuHolder), 'duplicate_sku', field: 'sku');
        }

        $variant->fill([
            'sku' => $sku,
            'name' => $data['name'] ?? $product->name,
            'name_ur' => $data['name_ur'] ?? null,
            'base_unit_id' => $data['base_unit_id'],
            'is_loose' => (bool) ($data['is_loose'] ?? false),
            'mrp' => isset($data['mrp']) && $data['mrp'] !== '' ? Decimal::money($data['mrp']) : null,
            'attributes' => $data['attributes'] ?? null,
            'is_default' => $isFirst,
            'is_active' => (bool) ($data['is_active'] ?? true),
        ]);
        // Purchase price is only written when it is sent (people who may not see costs never send it).
        if (array_key_exists('purchase_price', $data)) {
            $variant->purchase_price = $data['purchase_price'] !== null && $data['purchase_price'] !== '' ? Decimal::round((string) $data['purchase_price'], 4) : null;
        }
        $variant->save();

        $unitIdMap = $this->saveUnits($variant, $data['units'] ?? []);
        $this->saveBarcodes($variant, $data['barcodes'] ?? [], $unitIdMap);
        if ($canChangePrices) {
            $this->savePrices($variant, $data['prices'] ?? [], $unitIdMap);
            // Rules are only touched when the form sends them (imports and other callers leave them alone).
            if (array_key_exists('price_rules', $data) && is_array($data['price_rules'])) {
                app(PriceRuleService::class)->syncVariant($variant->load('units'), $data['price_rules']);
            }
        }
        if ($product->type === 'bundle') {
            $this->saveBundle($variant, $data['bundle_components'] ?? []);
        }

        return $variant;
    }

    /**
     * @param  list<array<string, mixed>>  $units
     * @return array<int, int> unit_id => variant_unit id
     */
    private function saveUnits(ProductVariant $variant, array $units): array
    {
        $hasBase = false;
        foreach ($units as $unit) {
            if ((int) $unit['unit_id'] === (int) $variant->base_unit_id) {
                $hasBase = true;
            }
        }
        if (! $hasBase) {
            array_unshift($units, ['unit_id' => $variant->base_unit_id, 'factor' => '1']);
        }

        $map = [];
        $hasDefaultSale = collect($units)->contains(fn ($u) => ! empty($u['is_default_sale']));
        $hasDefaultPurchase = collect($units)->contains(fn ($u) => ! empty($u['is_default_purchase']));
        foreach ($units as $unit) {
            $isBase = (int) $unit['unit_id'] === (int) $variant->base_unit_id;
            $factor = $isBase ? '1' : (string) $unit['factor'];
            if (! is_numeric($factor) || ! Decimal::isPositive($factor)) {
                throw new BusinessRuleException('Each pack size must contain more than zero base units.', 'invalid_factor', field: 'factor');
            }
            $variantUnit = VariantUnit::query()->firstOrNew(['variant_id' => $variant->id, 'unit_id' => $unit['unit_id']]);
            $variantUnit->fill([
                'factor' => Decimal::cost($factor),
                'is_base' => $isBase,
                'can_sell' => (bool) ($unit['can_sell'] ?? true),
                'can_purchase' => (bool) ($unit['can_purchase'] ?? true),
                'is_default_sale' => (bool) ($unit['is_default_sale'] ?? (! $hasDefaultSale && $isBase)),
                'is_default_purchase' => (bool) ($unit['is_default_purchase'] ?? (! $hasDefaultPurchase && $isBase)),
            ]);
            $variantUnit->save();
            $map[(int) $unit['unit_id']] = $variantUnit->id;
        }

        $variant->units()->whereNotIn('id', array_values($map))->get()->each(function (VariantUnit $stale) {
            if (Price::query()->where('variant_unit_id', $stale->id)->exists()) {
                $stale->update(['can_sell' => false, 'can_purchase' => false, 'is_default_sale' => false, 'is_default_purchase' => false]);
            } else {
                $stale->delete();
            }
        });

        return $map;
    }

    /**
     * @param  list<array{code: string, unit_id?: int|null, symbology?: string}|string>  $barcodes
     * @param  array<int, int>  $unitIdMap
     */
    private function saveBarcodes(ProductVariant $variant, array $barcodes, array $unitIdMap): void
    {
        $keep = [];
        foreach ($barcodes as $index => $barcode) {
            $barcode = is_string($barcode) ? ['code' => $barcode] : $barcode;
            $code = trim((string) $barcode['code']);
            if ($code === '') {
                continue;
            }
            $existing = Barcode::query()->where('code', $code)->first();
            if ($existing && $existing->variant_id !== $variant->id) {
                $owner = $existing->variant?->displayName() ?? 'another product';
                throw new BusinessRuleException("Barcode {$code} already belongs to {$owner}.".$this->reservedNote($existing->variant), 'duplicate_barcode', field: 'barcodes');
            }
            $variantUnitId = isset($barcode['unit_id']) ? ($unitIdMap[(int) $barcode['unit_id']] ?? null) : null;
            $model = $existing ?? new Barcode(['variant_id' => $variant->id, 'code' => $code]);
            $model->fill([
                'variant_unit_id' => $variantUnitId,
                'symbology' => $barcode['symbology'] ?? (BarcodeLookup::hasValidCheckDigit($code) ? (strlen($code) === 8 ? 'EAN8' : 'EAN13') : 'CODE128'),
                'is_primary' => $index === 0,
            ]);
            $model->save();
            $keep[] = $model->id;
        }
        $variant->barcodes()->whereNotIn('id', $keep)->delete();
    }

    /**
     * @param  list<array{price_book_id: int, unit_id: int, price: string|int|float, min_quantity?: string|int|null}>  $prices
     * @param  array<int, int>  $unitIdMap
     */
    private function savePrices(ProductVariant $variant, array $prices, array $unitIdMap): void
    {
        foreach ($prices as $row) {
            if (! isset($row['price']) || $row['price'] === '' || $row['price'] === null) {
                // Emptying a price on another list (Wholesale ...) removes it. The In-store price is never removed this way.
                if (! empty($row['clear']) && (int) $row['price_book_id'] !== (int) app(PriceResolver::class)->defaultBookId() && ($variantUnitId = $unitIdMap[(int) $row['unit_id']] ?? null)) {
                    $this->endPrice($variant, (int) $row['price_book_id'], $variantUnitId);
                }

                continue;
            }
            $variantUnitId = $unitIdMap[(int) $row['unit_id']] ?? null;
            if ($variantUnitId === null) {
                throw new BusinessRuleException('A price was given for a pack size this product does not have.', 'price_unit_missing', field: 'prices');
            }
            $this->setPrice($variant, (int) $row['price_book_id'], $variantUnitId, Decimal::money((string) $row['price']), Decimal::qty($row['min_quantity'] ?? '1'));
        }
    }

    /** Ends the current price of a pack on one list (the field was emptied on the form). */
    private function endPrice(ProductVariant $variant, int $priceBookId, int $variantUnitId): void
    {
        $current = Price::query()->where('price_book_id', $priceBookId)->where('variant_id', $variant->id)->where('variant_unit_id', $variantUnitId)
            ->where('min_quantity', '<=', 1)->whereNull('effective_to')->get();
        foreach ($current as $price) {
            $price->update(['effective_to' => now(), 'note' => 'Removed on the product form']);
            Audit::record('price.removed', 'Price of '.$variant->sku.' removed', $price, ['price' => $price->price], ['price' => null, 'price_book_id' => $priceBookId]);
        }
    }

    /** Starts a new effective price if it differs from the current one. */
    public function setPrice(ProductVariant $variant, int $priceBookId, int $variantUnitId, string $price, string $minQuantity = '1.000', ?\DateTimeInterface $from = null, ?string $note = null): ?Price
    {
        if (Decimal::isNegative($price)) {
            throw new BusinessRuleException('A price cannot be negative.', 'invalid_price', field: 'price');
        }
        $from ??= now();
        $current = Price::query()
            ->where('price_book_id', $priceBookId)->where('variant_id', $variant->id)
            ->where('variant_unit_id', $variantUnitId)->where('min_quantity', $minQuantity)
            ->whereNull('effective_to')
            ->latest('effective_from')
            ->first();
        if ($current && Decimal::eq($current->price, $price)) {
            return null;
        }
        if ($current) {
            $current->update(['effective_to' => $from]);
        }
        $new = Price::create([
            'price_book_id' => $priceBookId,
            'variant_id' => $variant->id,
            'variant_unit_id' => $variantUnitId,
            'price' => $price,
            'min_quantity' => $minQuantity,
            'effective_from' => $from,
            'note' => $note,
            'created_by' => Auth::id(),
        ]);
        Audit::record('price.changed', 'Price of '.$variant->sku.' set to '.$price, $new, ['price' => $current?->price], ['price' => $price, 'price_book_id' => $priceBookId]);

        return $new;
    }

    /** @param list<array{component_variant_id: int, quantity: string|int}> $components */
    private function saveBundle(ProductVariant $bundle, array $components): void
    {
        $keep = [];
        foreach ($components as $component) {
            if ((int) $component['component_variant_id'] === $bundle->id) {
                throw new BusinessRuleException('A bundle cannot contain itself.', 'invalid_bundle');
            }
            $row = BundleComponent::query()->updateOrCreate(
                ['bundle_variant_id' => $bundle->id, 'component_variant_id' => $component['component_variant_id']],
                ['quantity' => Decimal::qty($component['quantity'])],
            );
            $keep[] = $row->id;
        }
        $bundle->bundleComponents()->whereNotIn('id', $keep)->delete();
    }

    /** @param list<int> $keptIds */
    private function retireMissingVariants(Product $product, array $keptIds): void
    {
        $product->variants()->whereNotIn('id', $keptIds)->get()->each(function (ProductVariant $variant) {
            if (StockMovement::query()->where('variant_id', $variant->id)->exists() || $variant->prices()->exists()) {
                $variant->update(['is_active' => false]);
            } else {
                $variant->units()->delete();
                $variant->barcodes()->delete();
                $variant->delete();
            }
        });
    }
}

/** Thrown inside the history probe so its transaction always rolls back. */
final class ProductDeleteProbe extends \RuntimeException {}
