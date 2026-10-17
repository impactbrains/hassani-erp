<?php

namespace App\Http\Controllers\Inventory;

use App\Domain\Inventory\RepackService;
use App\Http\Controllers\Controller;
use App\Models\RepackOrder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Breaking bulk into retail packs (e.g. a 50 kg rice bag into 1 kg packets). */
class RepackController extends Controller
{
    public function index(Request $request): Response
    {
        $store = $this->currentStore($request);
        $this->allow($request, 'inventory.repack', $store);

        return Inertia::render('inventory/Repack', ['orders' => RepackOrder::query()->where('store_id', $store->id)->with('sourceVariant:id,product_id,sku,name', 'sourceVariant.product:id,name', 'targetVariant:id,product_id,sku,name', 'targetVariant.product:id,name', 'sourceLocation:id,name', 'targetLocation:id,name')->latest('id')->paginate(30)]);
    }

    public function store(Request $request, RepackService $repack): RedirectResponse
    {
        $this->allow($request, 'inventory.repack', $this->currentStore($request));
        $data = $request->validate(['source_variant_id' => ['required', 'integer'], 'source_location_id' => ['required', 'integer'], 'source_quantity' => ['required', 'numeric', 'gt:0'], 'target_variant_id' => ['required', 'integer'], 'target_location_id' => ['required', 'integer'], 'target_quantity' => ['required', 'numeric', 'gt:0'], 'packaging_variant_id' => ['nullable', 'integer'], 'packaging_quantity' => ['nullable', 'numeric', 'min:0'], 'note' => ['nullable', 'string', 'max:300']]);
        $order = $repack->post($request->user(), $data);

        return back()->with('success', "Repack {$order->number} posted.");
    }
}
