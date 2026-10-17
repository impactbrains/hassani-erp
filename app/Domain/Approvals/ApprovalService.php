<?php

namespace App\Domain\Approvals;

use App\Exceptions\BusinessRuleException;
use App\Models\Approval;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Manager approvals at the counter. A manager enters their PIN on the
 * cashier's screen; the server checks the PIN and the manager's permission
 * for that store and returns a single-use token that is valid for a few
 * minutes and only for that action. The token is checked again (and burnt)
 * when the sale, refund or payout is actually posted.
 */
final class ApprovalService
{
    public const ACTIONS = [
        'discount' => 'pos.discount.approve',
        'price_override' => 'pos.price.override',
        'refund' => 'pos.refund.approve',
        'payout' => 'pos.cash.payout.approve',
        'no_sale' => 'pos.no_sale.approve',
        'credit_limit' => 'sales.credit_limit.override',
        'cash_variance' => 'pos.shift.approve_variance',
    ];

    private const TOKEN_MINUTES = 10;

    private const MAX_PIN_ATTEMPTS = 5;

    /** Whether the requester can do the action without anyone else's PIN. */
    public function canSelfApprove(User $requester, string $action, int $storeId): bool
    {
        return $requester->hasPermission($this->permissionFor($action), $storeId);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{approval: Approval, token: string}
     */
    public function grant(User $requester, User $approver, string $pin, string $action, int $storeId, array $context = [], ?string $reason = null): array
    {
        $throttleKey = "approval-pin:{$approver->id}";
        if (RateLimiter::tooManyAttempts($throttleKey, self::MAX_PIN_ATTEMPTS)) {
            throw new BusinessRuleException('Too many wrong PIN attempts. Try again in a few minutes.', 'pin_locked');
        }

        if ($approver->company_id !== $requester->company_id || ! $approver->isActive() || ! $approver->pin_hash || ! Hash::check($pin, $approver->pin_hash)) {
            RateLimiter::hit($throttleKey, 300);
            Audit::record('approval.pin_failed', "Failed approval PIN for {$action}", $approver, context: ['requested_by' => $requester->id], storeId: $storeId);
            throw new BusinessRuleException('That PIN is not correct.', 'pin_invalid', field: 'pin');
        }
        RateLimiter::clear($throttleKey);

        if (! $approver->hasPermission($this->permissionFor($action), $storeId)) {
            throw new BusinessRuleException("{$approver->name} is not allowed to approve this.", 'approver_not_permitted');
        }

        $token = Str::random(48);
        $approval = Approval::create([
            'store_id' => $storeId,
            'action' => $action,
            'status' => 'granted',
            'requested_by' => $requester->id,
            'approved_by' => $approver->id,
            'context' => $context,
            'reason' => $reason,
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addMinutes(self::TOKEN_MINUTES),
        ]);
        Audit::record('approval.granted', "{$approver->name} approved {$action} for {$requester->name}", $approval, new: $context, storeId: $storeId);

        return ['approval' => $approval, 'token' => $token];
    }

    /** Checks a token for an action and marks it used. Call inside the posting transaction. */
    public function consume(?string $token, string $action, int $storeId, User $requester): Approval
    {
        if (! $token) {
            throw new BusinessRuleException('This needs a manager approval.', 'approval_required', ['action' => $action]);
        }
        $approval = Approval::query()
            ->where('token_hash', hash('sha256', $token))
            ->lockForUpdate()
            ->first();

        $valid = $approval
            && $approval->action === $action
            && $approval->store_id === $storeId
            && $approval->requested_by === $requester->id
            && $approval->status === 'granted'
            && $approval->expires_at->isFuture();
        if (! $valid) {
            throw new BusinessRuleException('The manager approval is missing, expired or already used. Ask for approval again.', 'approval_invalid', ['action' => $action]);
        }

        $approval->status = 'consumed';
        $approval->consumed_at = now();
        $approval->save();

        return $approval;
    }

    public function permissionFor(string $action): string
    {
        return self::ACTIONS[$action] ?? throw new \InvalidArgumentException("Unknown approval action [{$action}]");
    }
}
