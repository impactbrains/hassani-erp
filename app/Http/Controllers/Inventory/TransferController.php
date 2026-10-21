<?php

namespace App\Http\Controllers\Inventory;

use App\Domain\Inventory\TransferService;
use App\Http\Controllers\Controller;
use App\Models\StockTransfer;
use App\Support\Pager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Moving stock between godown and shop floor, or between stores (through "in transit"). */
class TransferController extends Controller
{
    public function index(Request $request): Response
    {
        Pager::remember($request, 'inventory.transfers');

        return Inertia::render('inventory/Transfers', [
            'transfers' => $this->listQuery($request)->with('fromStore:id,name', 'toStore:id,name', 'fromLocation:id,name', 'toLocation:id,name', 'requester:id,name')->paginate($this->perPage($request))->withQueryString(),
            'filters' => $request->only(['status']),
        ]);
    }

    /** The transfers list as filtered on screen. The next / previous arrows on a transfer walk through this same list. */
    private function listQuery(Request $request): \Illuminate\Database\Eloquent\Builder
    {
        $stores = $request->user()->accessibleStoreIds();

        return StockTransfer::query()->where(fn ($q) => $q->whereIn('from_store_id', $stores)->orWhereIn('to_store_id', $stores))
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))->latest('id');
    }

    public function create(Request $request): Response
    {
        $this->allow($request, 'inventory.transfers.manage', $this->currentStore($request));

        return Inertia::render('inventory/TransferForm');
    }

    public function store(Request $request, TransferService $transfers): RedirectResponse
    {
        $data = $request->validate(['from_location_id' => ['required', 'integer'], 'to_location_id' => ['required', 'integer', 'different:from_location_id'], 'note' => ['nullable', 'string', 'max:500'], 'lines' => ['required', 'array', 'min:1'], 'lines.*.variant_id' => ['required', 'integer'], 'lines.*.quantity' => ['required', 'numeric', 'gt:0'], 'lines.*.batch_id' => ['nullable', 'integer']]);
        $transfer = $transfers->create($request->user(), $data['from_location_id'], $data['to_location_id'], $data['lines'], $data['note'] ?? null);

        return redirect()->route('inventory.transfers.show', $transfer)->with('success', "Transfer {$transfer->number} created.");
    }

    public function show(Request $request, StockTransfer $transfer): Response
    {
        $user = $request->user();
        abort_unless(array_intersect([$transfer->from_store_id, $transfer->to_store_id], $user->accessibleStoreIds()) !== [], 403);

        return Inertia::render('inventory/TransferShow', [
            'pager' => Pager::for($request, 'inventory.transfers', $transfer->id, fn (Request $r) => $this->listQuery($r), fn (int $id) => route('inventory.transfers.show', $id)),
            'transfer' => $transfer->load('lines.variant:id,product_id,sku,name', 'lines.variant.product:id,name', 'fromStore:id,name', 'toStore:id,name', 'fromLocation:id,name', 'toLocation:id,name', 'requester:id,name'),
            'can' => [
                'approve' => $user->hasPermission('inventory.transfers.approve', $transfer->from_store_id),
                'dispatch' => $user->hasPermission('inventory.transfers.manage', $transfer->from_store_id),
                'receive' => $user->hasPermission('inventory.transfers.manage', $transfer->to_store_id),
            ],
        ]);
    }

    public function action(Request $request, StockTransfer $transfer, TransferService $transfers): RedirectResponse
    {
        $data = $request->validate(['action' => ['required', 'in:approve,dispatch,receive,cancel'], 'lines' => ['array'], 'reason' => ['nullable', 'string', 'max:300']]);
        $user = $request->user();
        match ($data['action']) {
            'approve' => $transfers->approve($user, $transfer),
            'dispatch' => $transfers->dispatch($user, $transfer, collect($data['lines'] ?? [])->mapWithKeys(fn ($l) => [(int) $l['id'] => (string) $l['quantity']])->all()),
            'receive' => $transfers->receive($user, $transfer, collect($data['lines'] ?? [])->mapWithKeys(fn ($l) => [(int) $l['id'] => ['quantity' => (string) $l['quantity'], 'reason_id' => $l['reason_id'] ?? null, 'note' => $l['note'] ?? null]])->all()),
            'cancel' => $transfers->cancel($user, $transfer, $data['reason'] ?? 'Cancelled'),
        };

        return back()->with('success', 'Transfer updated.');
    }
}
