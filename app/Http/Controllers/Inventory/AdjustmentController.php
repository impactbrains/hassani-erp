<?php

namespace App\Http\Controllers\Inventory;

use App\Domain\Inventory\AdjustmentService;
use App\Http\Controllers\Controller;
use App\Models\StockAdjustment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Opening stock, corrections, write-offs (damage, expiry) and quarantine, with approvals by value. */
class AdjustmentController extends Controller
{
    public function index(Request $request): Response
    {
        $stores = $request->user()->storeIdsFor('inventory.adjust');
        $stores = array_values(array_unique([...$stores, ...$request->user()->storeIdsFor('inventory.adjust.approve')]));

        return Inertia::render('inventory/Adjustments', [
            'adjustments' => StockAdjustment::query()->whereIn('store_id', $stores)->with('store:id,name', 'reasonCode:id,label', 'requester:id,name', 'approver:id,name')
                ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))->when($request->query('kind'), fn ($q, $k) => $q->where('kind', $k))
                ->latest('id')->paginate($this->perPage($request))->withQueryString(),
            'filters' => $request->only(['status', 'kind']),
        ]);
    }

    public function create(Request $request): Response
    {
        $this->allow($request, 'inventory.adjust', $this->currentStore($request));

        return Inertia::render('inventory/AdjustmentForm', ['kind' => $request->query('kind', 'write_off')]);
    }

    public function store(Request $request, AdjustmentService $adjustments): RedirectResponse
    {
        $data = $request->validate([
            'kind' => ['required', 'in:adjustment,write_off,opening,quarantine,release'],
            'reason_code_id' => ['nullable', 'integer'],
            'note' => ['nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:1', 'max:500'],
            'lines.*.variant_id' => ['required', 'integer'],
            'lines.*.location_id' => ['required', 'integer'],
            'lines.*.quantity' => ['required', 'numeric', 'not_in:0'],
            'lines.*.batch_id' => ['nullable', 'integer'],
            'lines.*.batch_no' => ['nullable', 'string', 'max:60'],
            'lines.*.expiry_date' => ['nullable', 'date'],
            'lines.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
            'lines.*.reason_code_id' => ['nullable', 'integer'],
            'lines.*.note' => ['nullable', 'string', 'max:200'],
        ]);
        $adjustment = $adjustments->create($request->user(), $this->currentStore($request), $data['kind'], $data['lines'], $data['reason_code_id'] ?? null, $data['note'] ?? null);

        return redirect()->route('inventory.adjustments.show', $adjustment)->with('success', $adjustment->status === 'posted' ? "Posted {$adjustment->number}." : "{$adjustment->number} is waiting for approval.");
    }

    public function show(Request $request, StockAdjustment $adjustment): Response
    {
        $this->allow($request, 'inventory.stock.view', $adjustment->store_id);
        $user = $request->user();

        return Inertia::render('inventory/AdjustmentShow', [
            'adjustment' => $adjustment->load('lines.variant:id,product_id,sku,name', 'lines.variant.product:id,name', 'lines.location:id,name', 'reasonCode:id,label', 'requester:id,name', 'approver:id,name', 'secondApprover:id,name', 'store:id,name'),
            'canApprove' => $user->hasPermission('inventory.adjust.approve', $adjustment->store_id) && $adjustment->status === 'pending_approval' && $adjustment->requested_by !== $user->id,
            'showCost' => $user->hasPermission('catalog.costs.view'),
        ]);
    }

    public function decide(Request $request, StockAdjustment $adjustment, AdjustmentService $adjustments): RedirectResponse
    {
        $data = $request->validate(['decision' => ['required', 'in:approve,reject'], 'reason' => ['nullable', 'required_if:decision,reject', 'string', 'max:300']]);
        $data['decision'] === 'approve' ? $adjustments->approve($request->user(), $adjustment) : $adjustments->reject($request->user(), $adjustment, $data['reason']);

        return back()->with('success', $data['decision'] === 'approve' ? 'Approved.' : 'Rejected.');
    }
}
