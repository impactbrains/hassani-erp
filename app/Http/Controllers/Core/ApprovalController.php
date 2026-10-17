<?php

namespace App\Http\Controllers\Core;

use App\Domain\Approvals\ApprovalService;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Manager PIN on the cashier's screen. Returns a single-use token for one
 * action in one store; the sale/refund/payout consumes it on posting.
 */
class ApprovalController extends Controller
{
    public function store(Request $request, ApprovalService $approvals): JsonResponse
    {
        $data = $request->validate([
            'action' => ['required', 'in:'.implode(',', array_keys(ApprovalService::ACTIONS))],
            'approver_email' => ['nullable', 'email'],
            'approver_id' => ['nullable', 'integer'],
            'pin' => ['required', 'string', 'max:8'],
            'context' => ['array'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);
        $store = $this->currentStore($request);
        $approver = User::query()->inCurrentCompany()->where('status', 'active')
            ->when($data['approver_id'] ?? null, fn ($q, $id) => $q->whereKey($id), fn ($q) => $q->where('email', mb_strtolower((string) ($data['approver_email'] ?? ''))))
            ->first();
        abort_unless($approver, 422, 'Choose the manager who is approving.');
        $result = $approvals->grant($request->user(), $approver, $data['pin'], $data['action'], $store->id, $data['context'] ?? [], $data['reason'] ?? null);

        return response()->json(['token' => $result['token'], 'approved_by' => $approver->name, 'expires_at' => $result['approval']->expires_at]);
    }

    /** People who may approve an action in the working store (names only). */
    public function approvers(Request $request, ApprovalService $approvals): JsonResponse
    {
        $action = (string) $request->query('action');
        abort_unless(isset(ApprovalService::ACTIONS[$action]), 404);
        $store = $this->currentStore($request);

        return response()->json(User::query()->inCurrentCompany()->where('status', 'active')->whereNotNull('pin_hash')->orderBy('name')->get(['id', 'name'])
            ->filter(fn (User $u) => $u->hasPermission($approvals->permissionFor($action), $store->id))->values());
    }
}
