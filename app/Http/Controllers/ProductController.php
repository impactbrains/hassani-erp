<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Location;
use App\Models\Media;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\Tax;
use App\Models\Unit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ProductController extends Controller
{
    public function index(Request $request): Response
    {
        $products = Product::with(['category', 'variants.media', 'media'])
            ->when($request->search, fn ($q, $search) => $q->where('name', 'like', "%{$search}%"))
            ->when($request->category_id, fn ($q, $categoryId) => $q->where('category_id', $categoryId))
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        $products->getCollection()->transform(function (Product $product) {
            $product->image_url = $product->media?->resolvedUrl();
            $product->variants->each(function (ProductVariant $variant) {
                $variant->image_url = $variant->media?->resolvedUrl();
            });
            return $product;
        });

        return Inertia::render('products/Index', [
            'products' => $products,
            'categories' => ProductCategory::orderBy('name')->get(['id', 'name']),
            'filters' => $request->only(['search', 'category_id']),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('products/Form', [
            'product' => null,
            'categories' => ProductCategory::orderBy('name')->get(['id', 'name']),
            'units' => Unit::orderBy('name')->get(['id', 'name', 'short_code']),
            'taxes' => Tax::where('is_active', true)->orderBy('name')->get(['id', 'name', 'rate', 'type', 'is_default']),
            'locations' => Location::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function edit(Product $product): Response
    {
        $product->load(['variants.media', 'media']);
        $product->image_url = $product->media?->resolvedUrl();

        $locations = Location::orderBy('name')->get(['id', 'name']);

        // Pull current stock per variant per location in one query rather
        // than N+1-ing stockOnHand() per variant per location.
        $stockByVariantAndLocation = StockMovement::whereIn('variant_id', $product->variants->pluck('id'))
            ->select('variant_id', 'location_id', DB::raw('SUM(quantity_change) as total'))
            ->groupBy('variant_id', 'location_id')
            ->get()
            ->groupBy('variant_id');

        $product->variants->each(function (ProductVariant $variant) use ($locations, $stockByVariantAndLocation) {
            $variant->image_url = $variant->media?->resolvedUrl();

            $byLocation = $stockByVariantAndLocation->get($variant->id, collect())->keyBy('location_id');

            $variant->stock_by_location = $locations->mapWithKeys(fn ($location) => [
                $location->id => (float) ($byLocation->get($location->id)?->total ?? 0),
            ]);
        });

        return Inertia::render('products/Form', [
            'product' => $product,
            'categories' => ProductCategory::orderBy('name')->get(['id', 'name']),
            'units' => Unit::orderBy('name')->get(['id', 'name', 'short_code']),
            'taxes' => Tax::where('is_active', true)->orderBy('name')->get(['id', 'name', 'rate', 'type', 'is_default']),
            'locations' => $locations,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $this->validateProduct($request);

        $product = DB::transaction(function () use ($validated, $request) {
            $product = Product::create($validated['product']);
            $this->syncVariants($product, $validated['variants'], $request->user()->id);
            return $product;
        });

        ActivityLog::log('products', 'created', "Created product \"{$product->name}\"", $product);

        return redirect()->route('products.index')->with('success', "Product \"{$product->name}\" created.");
    }

    public function update(Request $request, Product $product)
    {
        $validated = $this->validateProduct($request, $product->id);

        DB::transaction(function () use ($product, $validated, $request) {
            $product->update($validated['product']);
            $this->syncVariants($product, $validated['variants'], $request->user()->id);
        });

        ActivityLog::log('products', 'updated', "Updated product \"{$product->name}\"", $product);

        return redirect()->route('products.index')->with('success', "Product \"{$product->name}\" updated.");
    }

    public function destroy(Product $product)
    {
        $name = $product->name;

        $hasHistory = ProductVariant::where('product_id', $product->id)
            ->whereHas('documentLines')
            ->exists();

        if ($hasHistory) {
            $product->update(['is_active' => false]);
            $product->variants()->update(['is_active' => false]);

            ActivityLog::log('products', 'deactivated', "Deactivated product \"{$name}\" (has sales history)", $product);

            return redirect()->route('products.index')
                ->with('success', "\"{$name}\" has sales history, so it was deactivated instead of deleted.");
        }

        // Note: we deliberately do NOT delete the Media row here — it may be
        // shared/reused by other products, so media cleanup is a separate
        // concern (a future "unused media" cleanup tool), not tied to product deletion.
        $product->variants()->delete();
        $product->delete();

        ActivityLog::log('products', 'deleted', "Deleted product \"{$name}\"");

        return redirect()->route('products.index')->with('success', "Product \"{$name}\" deleted.");
    }

    public function lookupBarcode(Request $request)
    {
        $request->validate(['barcode' => ['required', 'string']]);

        $variant = ProductVariant::with(['product', 'media'])
            ->where('barcode', $request->barcode)
            ->first();

        if ($variant) {
            $variant->image_url = $variant->media?->resolvedUrl();
        }

        return response()->json([
            'found' => (bool) $variant,
            'variant' => $variant,
        ]);
    }

    private function validateProduct(Request $request, ?int $productId = null): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'category_id' => ['nullable', 'exists:product_categories,id'],
            'brand' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'tax_id' => ['nullable', 'exists:taxes,id'],
            'is_active' => ['boolean'],
            'can_edit_price' => ['boolean'],
            'media_id' => ['nullable', 'exists:media,id'],
            'variants' => ['required', 'array', 'min:1'],
            'variants.*.id' => ['nullable', 'integer', 'exists:product_variants,id'],
            'variants.*.name' => ['required', 'string', 'max:255'],
            'variants.*.sku' => ['nullable', 'string', 'max:255'],
            'variants.*.barcode' => ['nullable', 'string', 'max:255'],
            'variants.*.price' => ['required', 'numeric', 'min:0'],
            'variants.*.cost' => ['nullable', 'numeric', 'min:0'],
            'variants.*.tax_id' => ['nullable', 'exists:taxes,id'],
            'variants.*.unit_id' => ['nullable', 'exists:units,id'],
            'variants.*.pack_size' => ['nullable', 'numeric', 'min:0'],
            'variants.*.low_stock_threshold' => ['nullable', 'numeric', 'min:0'],
            'variants.*.track_inventory' => ['boolean'],
            'variants.*.is_loose' => ['boolean'],
            'variants.*.is_active' => ['boolean'],
            'variants.*.can_edit_price' => ['boolean'],
            'variants.*.media_id' => ['nullable', 'exists:media,id'],
            'variants.*.stock_adjustments' => ['nullable', 'array'],
            'variants.*.stock_adjustments.*' => ['nullable', 'numeric'],
        ]);

        $this->assertBarcodesAreUnique($validated['variants']);

        return [
            'product' => collect($validated)->only([
                'name', 'category_id', 'brand', 'description', 'tax_id', 'is_active', 'can_edit_price', 'media_id',
            ])->toArray(),
            'variants' => $validated['variants'],
        ];
    }

    private function assertBarcodesAreUnique(array $variants): void
    {
        $seen = [];

        foreach ($variants as $index => $variant) {
            $barcode = $variant['barcode'] ?? null;

            if (! $barcode) {
                continue;
            }

            if (isset($seen[$barcode])) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    "variants.{$index}.barcode" => "This barcode is already used by another variant in this form.",
                ]);
            }
            $seen[$barcode] = true;

            $conflict = ProductVariant::where('barcode', $barcode)
                ->when(
                    $variant['id'] ?? null,
                    fn ($q, $selfId) => $q->where('id', '!=', $selfId)
                )
                ->exists();

            if ($conflict) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    "variants.{$index}.barcode" => "This barcode is already assigned to a different product.",
                ]);
            }
        }
    }

    private function syncVariants(Product $product, array $variantsInput, ?int $userId = null): void
    {
        $submittedIds = collect($variantsInput)->pluck('id')->filter()->all();

        $product->variants()
            ->whereNotIn('id', $submittedIds)
            ->get()
            ->each(function (ProductVariant $variant) {
                if ($variant->documentLines()->exists()) {
                    $variant->update(['is_active' => false]);
                } else {
                    $variant->delete();
                }
            });

        foreach ($variantsInput as $variantData) {
            // stock_adjustments isn't a column on product_variants — it's a
            // per-location "set stock to this value" instruction that gets
            // converted into StockMovement rows below, never written
            // directly to the variant itself.
            $variantId = $variantData['id'] ?? null;
            $stockAdjustments = $variantData['stock_adjustments'] ?? [];
            $variantData = collect($variantData)->except(['id', 'stock_adjustments'])->toArray();

            $variant = $product->variants()->updateOrCreate(
                ['id' => $variantId],
                $variantData
            );

            $this->applyStockAdjustments($variant, $stockAdjustments, $userId);
        }
    }

    /**
     * Compares each submitted target quantity against the variant's actual
     * current stock at that location, and records an `adjustment`
     * StockMovement for the difference. Never overwrites stock directly —
     * the ledger is always the source of truth, this just adds the
     * correcting entry needed to reach the requested total.
     */
    private function applyStockAdjustments(ProductVariant $variant, array $stockAdjustments, ?int $userId): void
    {
        foreach ($stockAdjustments as $locationId => $targetQuantity) {
            if ($targetQuantity === null || $targetQuantity === '') {
                continue;
            }

            $currentStock = $variant->stockOnHand((int) $locationId);
            $delta = round((float) $targetQuantity - $currentStock, 3);

            if ($delta === 0.0) {
                continue;
            }

            StockMovement::create([
                'variant_id' => $variant->id,
                'location_id' => (int) $locationId,
                'user_id' => $userId,
                'quantity_change' => $delta,
                'type' => 'adjustment',
                'note' => 'Adjusted via product form',
            ]);
        }
    }
}