<?php

namespace App\Http\Controllers\Catalog;

use App\Domain\Catalog\ProductService;
use App\Domain\Inventory\Availability;
use App\Domain\Inventory\OpeningStock;
use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\PriceBook;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\TaxCategory;
use App\Models\Unit;
use App\Models\VariantCost;
use App\Support\Pager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Products with their SKUs, pack sizes (piece, box, carton ...), barcodes,
 * price books and manually written Urdu names. Nothing is translated
 * automatically.
 */
class ProductController extends Controller
{
    public function index(Request $request, Availability $availability): Response
    {
        $this->allow($request, 'catalog.products.view');
        $store = $this->currentStore($request);
        $tab = in_array($request->query('tab'), ['active', 'deactivated', 'trash'], true) ? $request->query('tab') : 'active';
        if ($tab === 'trash') {
            app(ProductService::class)->purgeExpired(); // anything past 30 days goes for good
        }
        Pager::remember($request, 'catalog.products');
        $products = $this->listQuery($request)
            ->with(['category:id,name', 'brand:id,name', 'variants' => fn ($v) => $v->with('baseUnit:id,code', 'barcodes:id,variant_id,code')->orderBy('id')])
            ->paginate($this->perPage($request, 30))->withQueryString();
        $variantIds = $products->getCollection()->flatMap(fn ($p) => $p->variants->pluck('id'))->all();
        $stock = $availability->availableMany($store->id, $variantIds);
        $retail = PriceBook::query()->where('is_default', true)->value('id');
        $prices = \App\Models\Price::query()->where('price_book_id', $retail)->whereIn('variant_id', $variantIds)->whereNull('effective_to')
            ->whereHas('variantUnit', fn ($u) => $u->where('is_default_sale', true))->where('min_quantity', '<=', 1)->get(['variant_id', 'price'])->pluck('price', 'variant_id');
        $costs = $request->user()->hasPermission('catalog.costs.view') ? VariantCost::query()->where('store_id', $store->id)->whereIn('variant_id', $variantIds)->pluck('average_cost', 'variant_id') : collect();
        $products->getCollection()->each(function ($p) {
            $p->setAttribute('trash_days_left', $p->deleted_at ? max(0, ProductService::TRASH_DAYS - (int) $p->deleted_at->diffInDays(now())) : null);
        });
        $products->getCollection()->each(fn ($p) => $p->variants->each(function ($v) use ($stock, $prices, $costs) {
            $v->setAttribute('available', $stock[$v->id] ?? '0.000');
            $v->setAttribute('retail_price', $prices[$v->id] ?? null);
            $v->setAttribute('average_cost', $costs[$v->id] ?? null);
        }));

        return Inertia::render('catalog/Products', [
            'products' => $products,
            'filters' => $request->only(['q', 'category_id', 'brand_id', 'tab', 'missing']),
            'tab' => $tab,
            'counts' => [
                'active' => Product::query()->where('is_open_item', false)->where('is_active', true)->count(),
                'deactivated' => Product::query()->where('is_open_item', false)->where('is_active', false)->count(),
                'trash' => Product::onlyTrashed()->where('is_open_item', false)->count(),
            ],
            'trashDays' => ProductService::TRASH_DAYS,
            'isOwner' => $request->user()->isOwner(),
            'categories' => ProductCategory::query()->orderBy('name')->get(['id', 'name', 'name_ur']),
            'brands' => Brand::query()->orderBy('name')->get(['id', 'name']),
            'missingUrdu' => Product::query()->where('is_active', true)->whereNull('name_ur')->count(),
        ]);
    }

    public function create(Request $request): Response
    {
        $this->allow($request, 'catalog.products.manage');
        $pending = $request->integer('pending') ? \App\Models\PendingProduct::query()->find($request->integer('pending')) : null;

        $store = $this->currentStore($request);
        $user = $request->user();
        // Only the create screen gets this, so the Opening stock block never shows when editing (stock cannot be counted twice).
        $openingStock = [
            'store' => $store->name,
            'allowed' => $user->hasPermission('inventory.adjust', $store->id),
            'needs_approval' => ! $user->hasPermission('inventory.adjust.approve', $store->id),
            'show_cost' => $user->hasPermission('catalog.costs.view'),
        ];

        return Inertia::render('catalog/ProductForm', ['product' => null, 'prefill' => $pending?->only(['id', 'name', 'barcode', 'last_price']), 'openingStock' => $openingStock] + $this->formData());
    }

    public function store(Request $request, ProductService $products, OpeningStock $opening): RedirectResponse
    {
        $this->allow($request, 'catalog.products.manage');
        $data = $this->validated($request);
        $store = $this->currentStore($request);
        $user = $request->user();

        // Check everything that can be checked first, so the person gets a message next to the field and nothing is half-saved.
        $requested = OpeningStock::requested($data['variants']);
        $opening->assertCanRecord($user, $store, $data['type'], (bool) ($data['track_batches'] ?? false), (bool) ($data['track_expiry'] ?? false), $requested);

        // Product and opening stock are saved together: if the stock cannot be recorded, the product is rolled back too.
        [$product, $adjustment] = DB::transaction(function () use ($products, $opening, $data, $user, $store, $requested) {
            $product = $products->save($data, null, $user->hasPermission('catalog.prices.manage'));

            return [$product, $opening->record($user, $store, $product, $requested)];
        });
        if ($request->integer('pending_product_id') && ($pending = \App\Models\PendingProduct::query()->find($request->integer('pending_product_id')))) {
            app(\App\Domain\Catalog\UnlistedItems::class)->resolve($pending, 'created', $request->user()->id, $product->id);
        }
        $message = "{$product->name} saved.".($adjustment !== null && $adjustment->status !== 'posted' ? ' The opening stock is waiting for approval in Stock → Adjustments and does not count until then.' : '');

        return redirect()->route($request->boolean('add_another') ? 'catalog.products.create' : 'catalog.products.edit', $request->boolean('add_another') ? [] : $product)->with('success', $message);
    }

    /** The products list as filtered on screen. The next / previous arrows on a product walk through this same list. */
    private function listQuery(Request $request): \Illuminate\Database\Eloquent\Builder
    {
        $q = trim((string) $request->query('q'));
        $tab = in_array($request->query('tab'), ['active', 'deactivated', 'trash'], true) ? $request->query('tab') : 'active';

        return Product::query()->where('is_open_item', false)
            ->when($tab === 'trash', fn ($w) => $w->onlyTrashed())
            ->when($tab === 'deactivated', fn ($w) => $w->where('is_active', false))
            ->when($tab === 'active', fn ($w) => $w->where('is_active', true))
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('name', 'ilike', "%{$q}%")->orWhere('name_ur', 'ilike', "%{$q}%")
                ->orWhereHas('variants', fn ($v) => $v->where('sku', 'ilike', "%{$q}%")->orWhereHas('barcodes', fn ($b) => $b->where('code', $q)))))
            ->when($request->query('category_id'), fn ($w, $c) => $w->where('category_id', $c))
            ->when($request->query('brand_id'), fn ($w, $b) => $w->where('brand_id', $b))
            ->when($request->query('missing') === 'urdu', fn ($w) => $w->whereNull('name_ur'))
            ->orderBy('name')->orderBy('id');
    }

    public function edit(Request $request, Product $product): Response
    {
        $this->allow($request, 'catalog.products.view');
        $product->load(['variants' => fn ($v) => $v->orderBy('id')->with(['units.unit', 'barcodes', 'bundleComponents', 'priceRules', 'prices' => fn ($p) => $p->whereNull('effective_to')->where('min_quantity', '<=', 1)])]);
        if (! $request->user()->hasPermission('catalog.costs.view')) {
            $product->variants->each->makeHidden('purchase_price');
        }

        $pager = Pager::for($request, 'catalog.products', $product->id, fn (Request $r) => $this->listQuery($r), fn (int $id) => route('catalog.products.edit', $id));

        return Inertia::render('catalog/ProductForm', ['pager' => $pager, 'product' => $product, 'priceHistory' => \App\Models\Price::query()->whereIn('variant_id', $product->variants->pluck('id'))->with('priceBook:id,name', 'variantUnit.unit:id,code', 'creator:id,name')->latest('effective_from')->limit(30)->get()] + $this->formData($product));
    }

    public function update(Request $request, Product $product, ProductService $products): RedirectResponse
    {
        $this->allow($request, 'catalog.products.manage');
        $products->save($this->validated($request), $product, $request->user()->hasPermission('catalog.prices.manage'));

        return back()->with('success', "{$product->name} saved.");
    }

    /**
     * Delete: a product with no history goes to the Trash for 30 days; one with stock or sales
     * history goes to the Deactivated tab. Either way it can be restored.
     */
    public function destroy(Request $request, Product $product, ProductService $products): RedirectResponse
    {
        $this->allow($request, 'catalog.products.manage');
        abort_if($product->is_open_item, 422, 'This is a system item and cannot be deleted.');

        $result = $products->remove($product);

        return back()->with('success', $result === 'trashed'
            ? "{$product->name} was moved to the Trash. You can restore it for ".ProductService::TRASH_DAYS.' days.'
            : "{$product->name} has stock or sales history, so it was moved to the Deactivated tab. You can restore it from there.");
    }

    /** Deletes many products at once, each handled as above. */
    public function bulkDestroy(Request $request, ProductService $products): RedirectResponse
    {
        $this->allow($request, 'catalog.products.manage');
        $data = $request->validate(['ids' => ['required', 'array', 'min:1', 'max:200'], 'ids.*' => ['integer']]);

        $trashed = 0;
        $deactivated = 0;
        Product::query()->whereIn('id', $data['ids'])->where('is_open_item', false)->get()
            ->each(function (Product $product) use ($products, &$trashed, &$deactivated) {
                $products->remove($product) === 'trashed' ? $trashed++ : $deactivated++;
            });

        $parts = array_filter([
            $trashed ? "{$trashed} moved to the Trash" : null,
            $deactivated ? "{$deactivated} moved to Deactivated because they have stock or sales history" : null,
        ]);

        return back()->with('success', $parts ? ucfirst(implode(', ', $parts)).'.' : 'Nothing to delete.');
    }

    /** Restores from the Trash or the Deactivated tab. Anyone who can manage products may. */
    public function restore(Request $request, Product $product, ProductService $products): RedirectResponse
    {
        $this->allow($request, 'catalog.products.manage');
        $products->restore($product);

        return back()->with('success', "{$product->name} was restored.");
    }

    public function bulkRestore(Request $request, ProductService $products): RedirectResponse
    {
        $this->allow($request, 'catalog.products.manage');
        $data = $request->validate(['ids' => ['required', 'array', 'min:1', 'max:200'], 'ids.*' => ['integer']]);
        $count = 0;
        Product::withTrashed()->whereIn('id', $data['ids'])->where('is_open_item', false)->get()
            ->each(function (Product $product) use ($products, &$count) {
                $products->restore($product);
                $count++;
            });

        return back()->with('success', "{$count} restored.");
    }

    /** Removes a trashed product now instead of waiting 30 days. Owner only, because it cannot be undone. */
    public function forceDestroy(Request $request, Product $product, ProductService $products): RedirectResponse
    {
        $this->allow($request, 'catalog.products.manage');
        abort_unless($request->user()->isOwner(), 403, 'Only the owner can delete a product permanently.');
        abort_unless($product->trashed(), 422, 'Only products in the Trash can be deleted permanently.');

        $name = $product->name;
        $result = $products->purge($product);

        return back()->with('success', $result === 'purged' ? "{$name} was deleted permanently." : "{$name} has history, so it was moved to Deactivated instead.");
    }

    /** Quick Urdu-name entry for many products at once (no machine translation). */
    public function urduNames(Request $request): RedirectResponse
    {
        $this->allow($request, 'catalog.products.manage');
        $data = $request->validate(['names' => ['required', 'array', 'max:200'], 'names.*.id' => ['required', 'integer'], 'names.*.name_ur' => ['nullable', 'string', 'max:200']]);
        foreach ($data['names'] as $row) {
            Product::query()->whereKey($row['id'])->update(['name_ur' => $row['name_ur'] !== '' ? $row['name_ur'] : null]);
        }

        return back()->with('success', 'Urdu names saved.');
    }

    /** Printable shelf labels / barcode stickers. */
    public function labels(Request $request): Response
    {
        $this->allow($request, 'catalog.labels.print');
        $ids = array_map('intval', (array) $request->query('variant_ids', []));
        $retail = PriceBook::query()->where('is_default', true)->value('id');
        $variants = \App\Models\ProductVariant::query()->whereIn('id', $ids)->with(['product:id,name,name_ur', 'barcodes', 'units' => fn ($u) => $u->where('is_default_sale', true)->with('unit:id,code')])->get()
            ->map(fn ($v) => ['id' => $v->id, 'sku' => $v->sku, 'name' => $v->displayName(), 'name_ur' => $v->displayNameUr(), 'mrp' => $v->mrp, 'barcode' => $v->barcodes->first()?->code ?? $v->sku,
                'price' => ($u = $v->units->first()) ? app(\App\Domain\Catalog\PriceResolver::class)->resolve($v, $u, $retail)['price'] ?? null : null, 'unit' => $v->units->first()?->unit?->code]);

        return Inertia::render('catalog/Labels', ['variants' => $variants, 'copies' => max(1, min(100, $request->integer('copies', 1)))]);
    }

    /** @return array<string, mixed> */
    private function formData(?Product $product = null): array
    {
        $resolver = app(\App\Domain\Catalog\PriceResolver::class);
        $user = request()->user();

        return [
            'categories' => ProductCategory::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'name_ur', 'parent_id']),
            'brands' => Brand::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'units' => Unit::query()->where('is_active', true)->orderBy('name')->get(['id', 'code', 'name', 'name_ur', 'kind', 'allows_fraction']),
            'taxCategories' => TaxCategory::query()->orderBy('name')->get(['id', 'name']),
            // In-store first, then Wholesale, then any other price lists.
            'priceBooks' => PriceBook::query()->where('is_active', true)->get(['id', 'code', 'name', 'name_ur', 'kind', 'is_default'])
                ->sortBy(fn ($b) => [$b->is_default ? 0 : ((int) $b->id === (int) $resolver->wholesaleBookId() ? 1 : 2), $b->name])->values(),
            'wholesaleBookId' => $resolver->wholesaleBookId(),
            'canSeeCosts' => $user?->hasPermission('catalog.costs.view') ?? false,
            // Shared rules (category, brand, all products) that can apply to this product, to show on the form.
            'sharedRules' => \App\Models\PriceRule::query()->where('is_active', true)->where('scope', '!=', 'variant')
                ->where(fn ($q) => $q->where('scope', 'all')
                    ->orWhere(fn ($c) => $c->where('scope', 'category')->where('category_id', $product?->category_id ?? 0))
                    ->orWhere(fn ($b) => $b->where('scope', 'brand')->where('brand_id', $product?->brand_id ?? 0)))
                ->orderBy('sort_order')->get(['id', 'name', 'scope', 'match', 'conditions', 'action', 'value', 'category_id', 'brand_id']),
        ] + app(\App\Http\Controllers\Catalog\PriceRuleController::class)->builderData();
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $data = $this->rules($request);
        // Purchase price is a cost: people who may not see costs never change it (it is not even sent to them).
        if (! $request->user()->hasPermission('catalog.costs.view')) {
            foreach ($data['variants'] as $i => $variant) {
                unset($data['variants'][$i]['purchase_price']);
            }
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function rules(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'name_ur' => ['nullable', 'string', 'max:200'],
            'category_id' => ['nullable', 'integer'],
            'brand_id' => ['nullable', 'integer'],
            'manufacturer' => ['nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'description_ur' => ['nullable', 'string', 'max:2000'],
            'type' => ['required', 'in:stocked,service,bundle'],
            'tax_category_id' => ['nullable', 'integer'],
            'track_batches' => ['boolean'],
            'track_expiry' => ['boolean'],
            'shelf_life_days' => ['nullable', 'integer', 'min:0'],
            'is_online' => ['boolean'],
            'is_active' => ['boolean'],
            'variants' => ['required', 'array', 'min:1', 'max:50'],
            'variants.*.id' => ['nullable', 'integer'],
            'variants.*.sku' => ['nullable', 'string', 'max:60'],
            'variants.*.name' => ['required', 'string', 'max:200'],
            'variants.*.name_ur' => ['nullable', 'string', 'max:200'],
            'variants.*.base_unit_id' => ['required', 'integer'],
            'variants.*.is_loose' => ['boolean'],
            'variants.*.mrp' => ['nullable', 'numeric', 'min:0'],
            'variants.*.purchase_price' => ['nullable', 'numeric', 'min:0'],
            // Opening stock, used only when creating (store() reads them; update() never records stock).
            'variants.*.opening_quantity' => ['nullable', 'numeric', 'min:0'],
            'variants.*.opening_cost' => ['nullable', 'numeric', 'min:0'],
            'variants.*.opening_batch_no' => ['nullable', 'string', 'max:60'],
            'variants.*.opening_expiry_date' => ['nullable', 'date'],
            'variants.*.is_active' => ['boolean'],
            'variants.*.units' => ['array'],
            'variants.*.units.*.unit_id' => ['required', 'integer'],
            'variants.*.units.*.factor' => ['required', 'numeric', 'gt:0'],
            'variants.*.units.*.can_sell' => ['boolean'],
            'variants.*.units.*.can_purchase' => ['boolean'],
            'variants.*.units.*.is_default_sale' => ['boolean'],
            'variants.*.units.*.is_default_purchase' => ['boolean'],
            'variants.*.barcodes' => ['array'],
            'variants.*.barcodes.*.code' => ['required', 'string', 'max:64'],
            'variants.*.barcodes.*.unit_id' => ['nullable', 'integer'],
            'variants.*.prices' => ['array'],
            'variants.*.prices.*.price_book_id' => ['required', 'integer'],
            'variants.*.prices.*.unit_id' => ['required', 'integer'],
            'variants.*.prices.*.price' => ['nullable', 'numeric', 'min:0'],
            'variants.*.prices.*.min_quantity' => ['nullable', 'numeric', 'gt:0'],
            'variants.*.prices.*.clear' => ['boolean'],
            'variants.*.price_rules' => ['array', 'max:30'],
            'variants.*.price_rules.*.id' => ['nullable', 'integer'],
            'variants.*.price_rules.*.name' => ['nullable', 'string', 'max:120'],
            'variants.*.price_rules.*.match' => ['nullable', 'in:all,any'],
            'variants.*.price_rules.*.conditions' => ['array', 'max:10'],
            'variants.*.price_rules.*.action' => ['required', 'in:wholesale,fixed,percent_off'],
            'variants.*.price_rules.*.value' => ['nullable', 'numeric', 'min:0'],
            'variants.*.price_rules.*.unit_id' => ['nullable', 'integer'],
            'variants.*.price_rules.*.is_active' => ['boolean'],
            'variants.*.bundle_components' => ['array'],
            'variants.*.bundle_components.*.component_variant_id' => ['required', 'integer'],
            'variants.*.bundle_components.*.quantity' => ['required', 'numeric', 'gt:0'],
        ]);
    }
}
