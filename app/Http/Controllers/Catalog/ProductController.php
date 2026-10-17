<?php

namespace App\Http\Controllers\Catalog;

use App\Domain\Catalog\ProductService;
use App\Domain\Inventory\Availability;
use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\PriceBook;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\TaxCategory;
use App\Models\Unit;
use App\Models\VariantCost;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
        $q = trim((string) $request->query('q'));
        $products = Product::query()->where('is_open_item', false)->with(['category:id,name', 'brand:id,name', 'variants' => fn ($v) => $v->with('baseUnit:id,code', 'barcodes:id,variant_id,code')->orderBy('id')])
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('name', 'ilike', "%{$q}%")->orWhere('name_ur', 'ilike', "%{$q}%")
                ->orWhereHas('variants', fn ($v) => $v->where('sku', 'ilike', "%{$q}%")->orWhereHas('barcodes', fn ($b) => $b->where('code', $q)))))
            ->when($request->query('category_id'), fn ($w, $c) => $w->where('category_id', $c))
            ->when($request->query('brand_id'), fn ($w, $b) => $w->where('brand_id', $b))
            ->when($request->query('status', 'active') !== 'all', fn ($w) => $w->where('is_active', $request->query('status', 'active') === 'active'))
            ->when($request->query('missing') === 'urdu', fn ($w) => $w->whereNull('name_ur'))
            ->orderBy('name')->paginate($this->perPage($request, 30))->withQueryString();
        $variantIds = $products->getCollection()->flatMap(fn ($p) => $p->variants->pluck('id'))->all();
        $stock = $availability->availableMany($store->id, $variantIds);
        $retail = PriceBook::query()->where('is_default', true)->value('id');
        $prices = \App\Models\Price::query()->where('price_book_id', $retail)->whereIn('variant_id', $variantIds)->whereNull('effective_to')
            ->whereHas('variantUnit', fn ($u) => $u->where('is_default_sale', true))->where('min_quantity', '<=', 1)->get(['variant_id', 'price'])->pluck('price', 'variant_id');
        $costs = $request->user()->hasPermission('catalog.costs.view') ? VariantCost::query()->where('store_id', $store->id)->whereIn('variant_id', $variantIds)->pluck('average_cost', 'variant_id') : collect();
        $products->getCollection()->each(fn ($p) => $p->variants->each(function ($v) use ($stock, $prices, $costs) {
            $v->setAttribute('available', $stock[$v->id] ?? '0.000');
            $v->setAttribute('retail_price', $prices[$v->id] ?? null);
            $v->setAttribute('average_cost', $costs[$v->id] ?? null);
        }));

        return Inertia::render('catalog/Products', [
            'products' => $products,
            'filters' => $request->only(['q', 'category_id', 'brand_id', 'status', 'missing']),
            'categories' => ProductCategory::query()->orderBy('name')->get(['id', 'name', 'name_ur']),
            'brands' => Brand::query()->orderBy('name')->get(['id', 'name']),
            'missingUrdu' => Product::query()->where('is_active', true)->whereNull('name_ur')->count(),
        ]);
    }

    public function create(Request $request): Response
    {
        $this->allow($request, 'catalog.products.manage');
        $pending = $request->integer('pending') ? \App\Models\PendingProduct::query()->find($request->integer('pending')) : null;

        return Inertia::render('catalog/ProductForm', ['product' => null, 'prefill' => $pending?->only(['id', 'name', 'barcode', 'last_price'])] + $this->formData());
    }

    public function store(Request $request, ProductService $products): RedirectResponse
    {
        $this->allow($request, 'catalog.products.manage');
        $product = $products->save($this->validated($request), null, $request->user()->hasPermission('catalog.prices.manage'));
        if ($request->integer('pending_product_id') && ($pending = \App\Models\PendingProduct::query()->find($request->integer('pending_product_id')))) {
            app(\App\Domain\Catalog\UnlistedItems::class)->resolve($pending, 'created', $request->user()->id, $product->id);
        }

        return redirect()->route($request->boolean('add_another') ? 'catalog.products.create' : 'catalog.products.edit', $request->boolean('add_another') ? [] : $product)->with('success', "{$product->name} saved.");
    }

    public function edit(Request $request, Product $product): Response
    {
        $this->allow($request, 'catalog.products.view');
        $product->load(['variants' => fn ($v) => $v->orderBy('id')->with(['units.unit', 'barcodes', 'bundleComponents', 'prices' => fn ($p) => $p->whereNull('effective_to')])]);

        return Inertia::render('catalog/ProductForm', ['product' => $product, 'priceHistory' => \App\Models\Price::query()->whereIn('variant_id', $product->variants->pluck('id'))->with('priceBook:id,name', 'variantUnit.unit:id,code', 'creator:id,name')->latest('effective_from')->limit(30)->get()] + $this->formData());
    }

    public function update(Request $request, Product $product, ProductService $products): RedirectResponse
    {
        $this->allow($request, 'catalog.products.manage');
        $products->save($this->validated($request), $product, $request->user()->hasPermission('catalog.prices.manage'));

        return back()->with('success', 'Saved.');
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
    private function formData(): array
    {
        return [
            'categories' => ProductCategory::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'name_ur', 'parent_id']),
            'brands' => Brand::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'units' => Unit::query()->where('is_active', true)->orderBy('name')->get(['id', 'code', 'name', 'name_ur', 'kind', 'allows_fraction']),
            'taxCategories' => TaxCategory::query()->orderBy('name')->get(['id', 'name']),
            'priceBooks' => PriceBook::query()->where('is_active', true)->orderByDesc('is_default')->get(['id', 'code', 'name', 'is_default']),
        ];
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
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
            'variants.*.bundle_components' => ['array'],
            'variants.*.bundle_components.*.component_variant_id' => ['required', 'integer'],
            'variants.*.bundle_components.*.quantity' => ['required', 'numeric', 'gt:0'],
        ]);
    }
}
