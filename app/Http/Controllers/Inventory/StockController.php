<?php

namespace App\Http\Controllers\Inventory;

use App\Domain\Inventory\Availability;
use App\Domain\Inventory\BatchService;
use App\Domain\Purchasing\ReplenishmentService;
use App\Http\Controllers\Controller;
use App\Models\Location;
use App\Models\ProductVariant;
use App\Models\ReplenishmentPolicy;
use App\Models\StockBatch;
use App\Models\StockMovement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/** Stock look-up, batch status, reorder rules and suggestions. */
class StockController extends Controller
{
    public function index(Request $request): Response
    {
        $store = $this->currentStore($request);
        $this->allow($request, 'inventory.stock.view', $store);

        return Inertia::render('inventory/Stock', [
            'locations' => Location::query()->where('store_id', $store->id)->where('is_active', true)->orderBy('name')->get(['id', 'code', 'name', 'type', 'is_sellable']),
        ]);
    }

    /** Per-SKU breakdown: locations, batches, reservations and recent movements. */
    public function variant(Request $request, ProductVariant $variant, Availability $availability): JsonResponse
    {
        $store = $this->currentStore($request);
        $this->allow($request, 'inventory.stock.view', $store);
        $showCost = $request->user()->hasPermission('catalog.costs.view');

        return response()->json([
            'name' => $variant->displayName(), 'sku' => $variant->sku,
            'breakdown' => $availability->breakdown($store->id, $variant->id),
            'levels' => DB::table('stock_levels as sl')->join('locations as l', 'l.id', '=', 'sl.location_id')->leftJoin('stock_batches as b', 'b.id', '=', 'sl.batch_id')
                ->where('sl.store_id', $store->id)->where('sl.variant_id', $variant->id)->where('sl.on_hand', '!=', 0)
                ->get(['l.name as location', 'l.type', 'b.id as batch_id', 'b.batch_no', 'b.expiry_date', 'b.status as batch_status', 'sl.on_hand']),
            'movements' => StockMovement::query()->where('store_id', $store->id)->where('variant_id', $variant->id)->with('location:id,name', 'user:id,name')->latest('id')->limit(25)
                ->get(['id', 'location_id', 'user_id', 'movement_type', 'quantity', 'unit_cost', 'occurred_at', 'source_type', 'source_id'])
                ->map(fn ($m) => ['type' => $m->movement_type, 'quantity' => $m->quantity, 'unit_cost' => $showCost ? $m->unit_cost : null, 'at' => $m->occurred_at, 'location' => $m->location?->name, 'by' => $m->user?->name]),
            'cost' => $showCost ? DB::table('variant_costs')->where('store_id', $store->id)->where('variant_id', $variant->id)->first(['average_cost', 'last_purchase_cost', 'quantity']) : null,
        ]);
    }

    public function batches(Request $request): Response
    {
        $store = $this->currentStore($request);
        $this->allow($request, 'inventory.stock.view', $store);
        $q = trim((string) $request->query('q'));
        $batches = StockBatch::query()->with('variant:id,sku,name,product_id', 'variant.product:id,name', 'supplier:id,name')
            ->whereHas('variant', fn ($v) => $v->when($q !== '', fn ($w) => $w->where('name', 'ilike', "%{$q}%")->orWhere('sku', 'ilike', "%{$q}%")))
            ->when($request->query('status'), fn ($w, $s) => $w->where('status', $s))
            ->withSum(['levels as on_hand' => fn ($l) => $l->where('store_id', $store->id)], 'on_hand')
            ->orderByRaw('expiry_date NULLS LAST')->paginate(50)->withQueryString();

        return Inertia::render('inventory/Batches', ['batches' => $batches, 'filters' => $request->only(['q', 'status']), 'canManage' => $request->user()->hasPermission('inventory.batches.manage', $store)]);
    }

    public function batchStatus(Request $request, StockBatch $batch, BatchService $batches): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', 'in:available,quarantined,recalled'], 'reason' => ['required', 'string', 'max:300']]);
        $batches->setStatus($request->user(), $batch, $data['status'], $data['reason']);

        return back()->with('success', "Batch {$batch->batch_no} is now {$data['status']}.");
    }

    public function replenishment(Request $request, ReplenishmentService $replenishment): Response
    {
        $store = $this->currentStore($request);
        $this->allow($request, 'inventory.replenishment.manage', $store);

        return Inertia::render('inventory/Replenishment', [
            'suggestions' => $replenishment->suggestions($store->id, $request->integer('supplier_id') ?: null, $request->query('all') !== '1'),
            'policies' => ReplenishmentPolicy::query()->where('store_id', $store->id)->with('variant:id,product_id,sku,name', 'variant.product:id,name', 'preferredSupplier:id,name')->orderBy('id')->paginate(50),
            'filters' => $request->only(['supplier_id', 'all']),
        ]);
    }

    public function savePolicy(Request $request): RedirectResponse
    {
        $store = $this->currentStore($request);
        $this->allow($request, 'inventory.replenishment.manage', $store);
        $data = $request->validate(['variant_id' => ['required', 'integer'], 'reorder_point' => ['required', 'numeric', 'min:0'], 'min_quantity' => ['nullable', 'numeric', 'min:0'], 'max_quantity' => ['required', 'numeric', 'gte:reorder_point'], 'safety_stock' => ['nullable', 'numeric', 'min:0'], 'preferred_supplier_id' => ['nullable', 'integer'], 'lead_time_days' => ['nullable', 'integer', 'min:0', 'max:365'], 'order_multiple' => ['nullable', 'numeric', 'gt:0'], 'is_active' => ['boolean']]);
        ReplenishmentPolicy::query()->updateOrCreate(['store_id' => $store->id, 'variant_id' => $data['variant_id']], $data + ['is_active' => true]);

        return back()->with('success', 'Reorder rule saved.');
    }
}
