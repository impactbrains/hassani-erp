<?php

namespace App\Http\Controllers;

use App\Models\Location;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class InventoryController extends Controller
{
    /**
     * Stock overview — variant x location grid, with low-stock flags.
     */
    public function index(Request $request): Response
    {
        $locations = Location::orderBy('name')->get();

        $variants = ProductVariant::with(['product', 'unit'])
            ->where('track_inventory', true)
            ->when($request->search, fn ($q, $search) => $q->whereHas(
                'product',
                fn ($pq) => $pq->where('name', 'like', "%{$search}%")
            )->orWhere('name', 'like', "%{$search}%")->orWhere('sku', 'like', "%{$search}%"))
            ->orderBy('name')
            ->get();

        // Pull all relevant movements in one query rather than N+1-ing
        // stockOnHand() per variant per location.
        $stockByVariantAndLocation = StockMovement::whereIn('variant_id', $variants->pluck('id'))
            ->select('variant_id', 'location_id', DB::raw('SUM(quantity_change) as total'))
            ->groupBy('variant_id', 'location_id')
            ->get()
            ->groupBy('variant_id');

        $rows = $variants->map(function (ProductVariant $variant) use ($locations, $stockByVariantAndLocation) {
            $byLocation = $stockByVariantAndLocation->get($variant->id, collect())
                ->keyBy('location_id');

            $stockPerLocation = $locations->mapWithKeys(function (Location $location) use ($byLocation) {
                return [$location->id => (float) ($byLocation->get($location->id)?->total ?? 0)];
            });

            $totalStock = $stockPerLocation->sum();

            return [
                'id' => $variant->id,
                'name' => $variant->displayName(),
                'sku' => $variant->sku,
                'unit' => $variant->unit?->short_code,
                'low_stock_threshold' => $variant->low_stock_threshold,
                'stock_per_location' => $stockPerLocation,
                'total_stock' => $totalStock,
                'is_low_stock' => $variant->low_stock_threshold !== null
                    && $totalStock <= (float) $variant->low_stock_threshold,
            ];
        });

        return Inertia::render('inventory/Index', [
            'locations' => $locations,
            'rows' => $rows,
            'filters' => $request->only(['search']),
        ]);
    }

    /**
     * Form for recording a manual stock movement (adjustment, purchase,
     * transfer, return). Sales are recorded by POS, not from here.
     * Accepts an optional ?variant_id= to preselect a variant, e.g. when
     * launched from the "Record movement" action on the overview grid.
     */
    public function createMovement(Request $request): Response
    {
        $preselectedVariant = null;

        if ($request->variant_id) {
            $variant = ProductVariant::with('product')->find($request->variant_id);
            if ($variant) {
                $preselectedVariant = [
                    'id' => $variant->id,
                    'name' => $variant->displayName(),
                    'sku' => $variant->sku,
                ];
            }
        }

        return Inertia::render('inventory/RecordMovement', [
            'locations' => Location::orderBy('name')->get(),
            'preselectedVariant' => $preselectedVariant,
        ]);
    }

    /**
     * Lightweight JSON search endpoint powering the searchable variant
     * dropdown on the Record Movement form. Limited to 20 results —
     * the frontend narrows further as the user types.
     */
    public function searchVariants(Request $request)
    {
        $search = $request->get('search', '');

        $variants = ProductVariant::with('product')
            ->where('track_inventory', true)
            ->where(function ($q) use ($search) {
                $q->whereHas('product', fn ($pq) => $pq->where('name', 'like', "%{$search}%"))
                    ->orWhere('name', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%");
            })
            ->orderBy('name')
            ->limit(20)
            ->get()
            ->map(fn (ProductVariant $v) => [
                'id' => $v->id,
                'name' => $v->displayName(),
                'sku' => $v->sku,
            ]);

        return response()->json($variants);
    }

    public function storeMovement(Request $request)
    {
        $validated = $request->validate([
            'variant_id' => ['required', 'exists:product_variants,id'],
            'location_id' => ['required', 'exists:locations,id'],
            'type' => ['required', 'in:purchase,adjustment,return,transfer'],
            'quantity_change' => ['required', 'numeric', 'not_in:0'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $validated['user_id'] = $request->user()->id;

        StockMovement::create($validated);

        return redirect()->back()->with('success', 'Stock movement recorded.');
    }

    /**
     * Movement history / reporting tab — filterable ledger view.
     */
    public function history(Request $request): Response
    {
        $movements = StockMovement::with(['variant.product', 'location', 'user'])
            ->when($request->variant_id, fn ($q, $variantId) => $q->where('variant_id', $variantId))
            ->when($request->location_id, fn ($q, $locationId) => $q->where('location_id', $locationId))
            ->when($request->type, fn ($q, $type) => $q->where('type', $type))
            ->when($request->from, fn ($q, $from) => $q->whereDate('created_at', '>=', $from))
            ->when($request->to, fn ($q, $to) => $q->whereDate('created_at', '<=', $to))
            ->orderByDesc('created_at')
            ->paginate(50)
            ->withQueryString();

        return Inertia::render('inventory/History', [
            'movements' => $movements,
            'locations' => Location::orderBy('name')->get(['id', 'name']),
            'filters' => $request->only(['variant_id', 'location_id', 'type', 'from', 'to']),
        ]);
    }

    /**
     * Locations management — list/create/update/delete, mirroring the
     * products/manage/* pattern used for categories, units, and taxes.
     */
    public function locationsIndex(): Response
    {
        return Inertia::render('inventory/Locations', [
            'locations' => Location::orderBy('name')->get(),
        ]);
    }

    public function storeLocation(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:locations,name'],
            'type' => ['required', 'in:store,warehouse'],
            'address' => ['nullable', 'string', 'max:255'],
        ]);

        Location::create($validated);

        return redirect()->route('inventory.locations.index')->with('success', 'Location created.');
    }

    public function updateLocation(Request $request, Location $location)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:locations,name,' . $location->id],
            'type' => ['required', 'in:store,warehouse'],
            'address' => ['nullable', 'string', 'max:255'],
        ]);

        $location->update($validated);

        return redirect()->route('inventory.locations.index')->with('success', 'Location updated.');
    }

    public function destroyLocation(Location $location)
    {
        if ($location->is_default) {
            return redirect()->route('inventory.locations.index')
                ->with('error', 'Cannot delete the default location. Set a different location as default first.');
        }

        if ($location->stockMovements()->exists()) {
            return redirect()->route('inventory.locations.index')
                ->with('error', "Cannot delete \"{$location->name}\" — it has stock movement history.");
        }

        $name = $location->name;
        $location->delete();

        return redirect()->route('inventory.locations.index')->with('success', "Location \"{$name}\" deleted.");
    }

    public function setDefaultLocation(Location $location)
    {
        DB::transaction(function () use ($location) {
            Location::where('is_default', true)->update(['is_default' => false]);
            $location->update(['is_default' => true]);
        });

        return redirect()->route('inventory.locations.index')->with('success', "\"{$location->name}\" is now the default location.");
    }
}