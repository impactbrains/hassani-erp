<?php

namespace App\Http\Controllers\Pos;

use App\Domain\Pos\ShiftReport;
use App\Domain\Pos\ShiftService;
use App\Http\Controllers\Controller;
use App\Models\Register;
use App\Models\Shift;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ShiftController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $stores = $user->storeIdsFor('pos.shift.view_all');
        $shifts = Shift::query()->with(['register:id,name', 'opener:id,name', 'store:id,name'])
            ->where(fn ($q) => $q->whereIn('store_id', $stores)->orWhere('opened_by', $user->id))
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('variance') === 'pending', fn ($q) => $q->where('variance_status', 'pending_review'))
            ->latest('opened_at')->paginate($this->perPage($request))->withQueryString();

        return Inertia::render('pos/Shifts', ['shifts' => $shifts, 'filters' => $request->only(['status', 'variance'])]);
    }

    public function show(Request $request, Shift $shift, ShiftReport $reports): Response
    {
        $this->canSee($request, $shift);

        return Inertia::render('pos/ShiftShow', [
            'shift' => $shift->load('register:id,name', 'opener:id,name', 'closer:id,name'),
            'report' => $shift->status === 'closed' && $shift->z_snapshot ? $shift->z_snapshot : $reports->build($shift),
            'canReview' => $request->user()->hasPermission('pos.shift.approve_variance', $shift->store_id) && $shift->variance_status === 'pending_review' && $shift->opened_by !== $request->user()->id,
        ]);
    }

    public function open(Request $request, ShiftService $shifts): JsonResponse
    {
        $data = $request->validate(['register_id' => ['required', 'integer'], 'denominations' => ['required', 'array']]);
        $register = Register::query()->where('store_id', $this->currentStore($request)->id)->findOrFail($data['register_id']);
        $shift = $shifts->open($request->user(), $register, $data['denominations']);

        return response()->json(['id' => $shift->id, 'number' => $shift->number, 'register_id' => $shift->register_id, 'register' => $register->name, 'opened_at' => $shift->opened_at]);
    }

    public function xReport(Request $request, Shift $shift, ShiftService $shifts): JsonResponse
    {
        return response()->json($shifts->xReport($request->user(), $shift));
    }

    public function cash(Request $request, Shift $shift, ShiftService $shifts): JsonResponse
    {
        $data = $request->validate(['type' => ['required', 'in:payout,drop,pay_in,no_sale'], 'amount' => ['nullable', 'numeric', 'min:0'], 'reason_code_id' => ['nullable', 'integer'], 'recipient' => ['nullable', 'string', 'max:120'], 'note' => ['nullable', 'string', 'max:500'], 'approval_token' => ['nullable', 'string']]);
        $movement = $shifts->recordCashMovement($request->user(), $shift, $data);

        return response()->json(['number' => $movement->number, 'type' => $movement->type, 'amount' => $movement->amount, 'open_drawer' => true]);
    }

    public function close(Request $request, Shift $shift, ShiftService $shifts, ShiftReport $reports): JsonResponse
    {
        $data = $request->validate(['denominations' => ['required', 'array'], 'note' => ['nullable', 'string', 'max:500'], 'approval_token' => ['nullable', 'string']]);
        $closed = $shifts->close($request->user(), $shift, $data['denominations'], $data['note'] ?? null, $data['approval_token'] ?? null);

        return response()->json(['id' => $closed->id, 'z_number' => $closed->z_number, 'variance' => $closed->variance, 'variance_status' => $closed->variance_status, 'report' => $closed->z_snapshot ?? $reports->build($closed)]);
    }

    public function review(Request $request, Shift $shift, ShiftService $shifts): RedirectResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'min:5', 'max:500']]);
        $shifts->reviewVariance($request->user(), $shift, $data['note']);

        return back()->with('success', 'Variance reviewed.');
    }

    private function canSee(Request $request, Shift $shift): void
    {
        $user = $request->user();
        abort_unless($shift->opened_by === $user->id || $user->hasPermission('pos.shift.view_all', $shift->store_id), 403);
    }
}
