<?php

namespace App\Http\Controllers\Inventory;

use App\Domain\Inventory\CountService;
use App\Http\Controllers\Controller;
use App\Models\StockCount;
use App\Models\StockCountLine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Full, cycle and spot counts; blind counting hides the expected quantity from counters. */
class CountController extends Controller
{
    public function index(Request $request): Response
    {
        $stores = $request->user()->storeIdsFor('inventory.counts.count');

        return Inertia::render('inventory/Counts', [
            'counts' => StockCount::query()->whereIn('store_id', $stores)->with('store:id,name', 'assignee:id,name', 'creator:id,name')->withCount('lines')
                ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))->latest('id')->paginate($this->perPage($request))->withQueryString(),
            'filters' => $request->only(['status']),
            'canPlan' => $request->user()->hasPermission('inventory.counts.manage', $this->currentStore($request)),
        ]);
    }

    public function store(Request $request, CountService $counts): RedirectResponse
    {
        $data = $request->validate(['scope' => ['required', 'in:full,location,category,products'], 'location_ids' => ['array'], 'category_ids' => ['array'], 'variant_ids' => ['array'], 'is_blind' => ['boolean'], 'requires_second_count' => ['boolean'], 'assigned_to' => ['nullable', 'integer'], 'note' => ['nullable', 'string', 'max:500']]);
        $filter = array_filter(['location_ids' => $data['location_ids'] ?? [], 'category_ids' => $data['category_ids'] ?? [], 'variant_ids' => $data['variant_ids'] ?? []]);
        $count = $counts->plan($request->user(), $this->currentStore($request), $data['scope'], $filter, (bool) ($data['is_blind'] ?? true), (bool) ($data['requires_second_count'] ?? false), $data['assigned_to'] ?? null, $data['note'] ?? null);

        return redirect()->route('inventory.counts.show', $count);
    }

    public function show(Request $request, StockCount $count, CountService $counts): Response
    {
        $user = $request->user();
        $this->allow($request, 'inventory.counts.count', $count->store_id);
        $canApprove = $user->hasPermission('inventory.counts.approve', $count->store_id);
        $hideExpected = $count->is_blind && ! $canApprove && in_array($count->status, ['in_progress', 'draft'], true);
        $count->load(['lines' => fn ($l) => $l->with('variant:id,product_id,sku,name,name_ur', 'variant.product:id,name', 'location:id,name', 'batch:id,batch_no', 'counter:id,name', 'reasonCode:id,label')->orderBy('location_id')->orderBy('id'), 'store:id,name', 'assignee:id,name']);
        if ($hideExpected) {
            $count->lines->each(fn ($line) => $line->makeHidden(['expected_quantity', 'variance_quantity', 'variance_value', 'movement_since_snapshot']));
        }
        if (! $user->hasPermission('catalog.costs.view')) {
            $count->lines->each(fn ($line) => $line->makeHidden(['unit_cost', 'variance_value']));
        }

        return Inertia::render('inventory/CountShow', [
            'count' => $count,
            'hideExpected' => $hideExpected,
            'needsSecondCount' => $count->status === 'submitted' ? $counts->linesNeedingSecondCount($count)->pluck('id') : [],
            'can' => ['manage' => $user->hasPermission('inventory.counts.manage', $count->store_id), 'approve' => $canApprove],
        ]);
    }

    public function action(Request $request, StockCount $count, CountService $counts): RedirectResponse
    {
        $data = $request->validate(['action' => ['required', 'in:start,submit,approve'], 'reasons' => ['array']]);
        match ($data['action']) {
            'start' => $counts->start($request->user(), $count),
            'submit' => $counts->submit($request->user(), $count),
            'approve' => $counts->approveAndPost($request->user(), $count, array_map('intval', $data['reasons'] ?? [])),
        };

        return back()->with('success', match ($data['action']) { 'start' => 'Count started: expected stock has been snapshotted.', 'submit' => 'Count submitted for review.', default => 'Count approved and variances posted.' });
    }

    public function addLine(Request $request, StockCount $count, CountService $counts): JsonResponse
    {
        $data = $request->validate(['location_id' => ['required', 'integer'], 'variant_id' => ['required', 'integer'], 'batch_id' => ['nullable', 'integer']]);
        $line = $counts->addLine($request->user(), $count, $data['location_id'], $data['variant_id'], $data['batch_id'] ?? null);

        return response()->json(['id' => $line->id]);
    }

    public function record(Request $request, StockCountLine $line, CountService $counts): JsonResponse
    {
        $data = $request->validate(['quantity' => ['required', 'numeric', 'min:0'], 'second' => ['boolean']]);
        $line = $counts->record($request->user(), $line, (string) $data['quantity'], (bool) ($data['second'] ?? false));

        return response()->json(['id' => $line->id, 'counted_quantity' => $line->counted_quantity, 'second_count_quantity' => $line->second_count_quantity]);
    }
}
