<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Catalog\CatalogSearch;
use App\Domain\Inventory\Availability;
use App\Models\PriceBook;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Catalogue and live stock for the e-commerce website and app (only products marked "sell online"). */
class CatalogController extends ApiController
{
    public function categories(Request $request): JsonResponse
    {
        $this->ability($request, 'catalog:read');

        return response()->json(['data' => ProductCategory::query()->where('is_active', true)->orderBy('sort_order')->orderBy('name')->get(['id', 'parent_id', 'name', 'name_ur', 'slug'])]);
    }

    public function products(Request $request, CatalogSearch $catalog): JsonResponse
    {
        $this->ability($request, 'catalog:read');
        $store = $this->clientStore($request);
        $request->validate(['q' => ['nullable', 'string', 'max:100'], 'category_id' => ['nullable', 'integer'], 'limit' => ['nullable', 'integer', 'min:1', 'max:100']]);

        return response()->json(['data' => $catalog->search($request->string('q'), $store->id, $this->onlineBook(), $request->integer('limit', 50), $request->integer('category_id') ?: null, onlineOnly: true)]);
    }

    public function product(Request $request, ProductVariant $variant, CatalogSearch $catalog, Availability $availability): JsonResponse
    {
        $this->ability($request, 'catalog:read');
        abort_unless($variant->is_active && $variant->product?->is_active && $variant->product?->is_online, 404);
        $store = $this->clientStore($request);

        return response()->json(['data' => $catalog->card($variant, $store->id, $this->onlineBook(), null, $availability->availableMany($store->id, [$variant->id])) + ['description' => $variant->product->description, 'description_ur' => $variant->product->description_ur]]);
    }

    public function stock(Request $request, Availability $availability): JsonResponse
    {
        $this->ability($request, 'stock:read');
        $data = $request->validate(['variant_ids' => ['required', 'array', 'max:200'], 'variant_ids.*' => ['integer']]);
        $store = $this->clientStore($request);

        return response()->json(['data' => $availability->availableMany($store->id, array_map('intval', $data['variant_ids']))]);
    }

    private function onlineBook(): ?int
    {
        return PriceBook::query()->where('kind', 'online')->where('is_active', true)->value('id');
    }
}
