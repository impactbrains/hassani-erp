<?php

namespace App\Domain\Pos;

use App\Domain\Accounting\JournalPoster;
use App\Domain\Approvals\ApprovalService;
use App\Exceptions\BusinessRuleException;
use App\Models\CashMovement;
use App\Models\ReasonCode;
use App\Models\Register;
use App\Models\Shift;
use App\Models\User;
use App\Support\Audit;
use App\Support\Decimal;
use App\Support\Sequences;
use App\Support\Settings\Settings;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Cash shift lifecycle: open with a counted float, record drawer movements
 * (drops, pay-ins, payouts, no-sale openings), print X-Reports at any time,
 * and close with a denomination count that produces a frozen Z-Report.
 *
 * Expected cash = opening float + cash taken − cash refunded − payouts
 *                 − drops + pay-ins.
 * Card, wallet, bank and Khata amounts never count as drawer cash.
 * A variance outside the store tolerance needs a manager's review; it is
 * posted to "cash over and short", never deducted from anyone's wages.
 */
final class ShiftService
{
    public function __construct(
        private readonly ShiftReport $reports,
        private readonly ApprovalService $approvals,
        private readonly JournalPoster $journals,
    ) {
    }

    /** @param array<string, int|string> $denominations note value => count */
    public function open(User $user, Register $register, array $denominations): Shift
    {
        if (! $user->hasPermission('pos.shift.open', $register->store_id)) {
            throw new BusinessRuleException('You are not allowed to open shifts.', 'shift_open_not_permitted');
        }
        $float = $this->countTotal($denominations);

        try {
            return DB::transaction(function () use ($user, $register, $denominations, $float) {
                $store = $register->store;
                $shift = Shift::create([
                    'store_id' => $register->store_id,
                    'register_id' => $register->id,
                    'number' => Sequences::next('shift', $register->store_id, $store->code),
                    'status' => 'open',
                    'opened_by' => $user->id,
                    'opened_at' => now(),
                    'opening_float' => $float,
                    'opening_count' => $this->cleanCount($denominations),
                    'business_date' => $store->businessDate(),
                ]);
                Audit::record('shift.opened', "Opened shift {$shift->number} on {$register->name} with float Rs {$float}", $shift);

                return $shift;
            });
        } catch (UniqueConstraintViolationException) {
            throw new BusinessRuleException("{$register->name} already has an open shift.", 'shift_already_open');
        }
    }

    /**
     * Records a drawer movement. Payouts above the store threshold and
     * no-sale openings need a manager approval token.
     *
     * @param  array{type: string, amount?: string|null, reason_code_id?: int|null, recipient?: string|null, note?: string|null, approval_token?: string|null, attachment_media_id?: int|null}  $data
     */
    public function recordCashMovement(User $user, Shift $shift, array $data): CashMovement
    {
        return DB::transaction(function () use ($user, $shift, $data) {
            $shift = Shift::query()->lockForUpdate()->findOrFail($shift->id);
            $this->assertOwnOpenShift($user, $shift);
            $type = $data['type'];
            $amount = Decimal::money($data['amount'] ?? '0');
            $reason = isset($data['reason_code_id']) ? ReasonCode::query()->find($data['reason_code_id']) : null;
            $approvedBy = null;

            match ($type) {
                'drop', 'pay_in' => $this->requirePermission($user, 'pos.cash.drop', $shift->store_id),
                'payout' => $this->requirePermission($user, 'pos.cash.payout', $shift->store_id),
                'no_sale' => null,
                default => throw new BusinessRuleException('Unknown drawer movement.', 'invalid_cash_movement'),
            };
            if ($type !== 'no_sale' && ! Decimal::isPositive($amount)) {
                throw new BusinessRuleException('Enter an amount.', 'amount_required', field: 'amount');
            }
            if ($type === 'payout') {
                if ($reason === null || $reason->type !== 'payout') {
                    throw new BusinessRuleException('Choose what the payout is for.', 'reason_required', field: 'reason_code_id');
                }
                if ($reason->requires_note && empty($data['note'])) {
                    throw new BusinessRuleException('Add a note for this payout.', 'note_required', field: 'note');
                }
                $available = $this->reports->expectedCash($shift);
                if (Decimal::gt($amount, $available)) {
                    throw new BusinessRuleException("The drawer should only hold Rs {$available}.", 'not_enough_cash');
                }
                if (Decimal::gt($amount, (string) Settings::get('pos.payout_approval_amount', $shift->store_id))
                    && ! $this->approvals->canSelfApprove($user, 'payout', $shift->store_id)) {
                    $approvedBy = $this->approvals->consume($data['approval_token'] ?? null, 'payout', $shift->store_id, $user)->approved_by;
                }
            }
            if ($type === 'no_sale' && ! $this->approvals->canSelfApprove($user, 'no_sale', $shift->store_id)) {
                $approvedBy = $this->approvals->consume($data['approval_token'] ?? null, 'no_sale', $shift->store_id, $user)->approved_by;
            }
            if ($type === 'drop') {
                $available = $this->reports->expectedCash($shift);
                if (Decimal::gt($amount, $available)) {
                    throw new BusinessRuleException("The drawer should only hold Rs {$available}.", 'not_enough_cash');
                }
            }

            $movement = CashMovement::create([
                'store_id' => $shift->store_id,
                'shift_id' => $shift->id,
                'number' => Sequences::next('cash_movement', $shift->store_id, $shift->store->code),
                'type' => $type,
                'amount' => $amount,
                'reason_code_id' => $reason?->id,
                'recipient' => $data['recipient'] ?? null,
                'note' => $data['note'] ?? null,
                'attachment_media_id' => $data['attachment_media_id'] ?? null,
                'user_id' => $user->id,
                'approved_by' => $approvedBy,
                'occurred_at' => now(),
                'business_date' => $shift->store->businessDate(),
            ]);

            if ($type === 'payout') {
                $this->journals->post('payout', $movement, $movement->business_date->toDateString(), "POS payout {$movement->number}: {$reason->label}", [
                    ['account' => $reason->account_id ?? 'misc_expense', 'debit' => $amount, 'memo' => $data['recipient'] ?? null],
                    ['account' => 'cash_on_hand', 'credit' => $amount],
                ], $shift->store_id);
            }
            Audit::record("cash.{$type}", ucfirst(str_replace('_', ' ', $type))." Rs {$amount} on shift {$shift->number}", $movement);

            return $movement;
        });
    }

    /** @return array<string, mixed> */
    public function xReport(User $user, Shift $shift): array
    {
        $this->assertCanView($user, $shift);
        Audit::record('shift.x_report', "X-Report printed for shift {$shift->number}", $shift);

        return $this->reports->build($shift);
    }

    /**
     * Closes the shift with a denomination count. Returns the shift with its
     * frozen Z-Report snapshot.
     *
     * @param  array<string, int|string>  $denominations
     */
    public function close(User $user, Shift $shift, array $denominations, ?string $note = null, ?string $approvalToken = null): Shift
    {
        return DB::transaction(function () use ($user, $shift, $denominations, $note, $approvalToken) {
            $shift = Shift::query()->lockForUpdate()->findOrFail($shift->id);
            if ($shift->status !== 'open') {
                throw new BusinessRuleException('This shift is already closed.', 'shift_already_closed');
            }
            if ($shift->opened_by !== $user->id && ! $user->hasPermission('pos.shift.view_all', $shift->store_id)) {
                throw new BusinessRuleException('Only the cashier who opened the shift or a manager can close it.', 'shift_close_not_permitted');
            }
            $this->requirePermission($user, 'pos.shift.close', $shift->store_id);

            $counted = $this->countTotal($denominations);
            $report = $this->reports->build($shift);
            $expected = $report['cash']['expected'];
            $variance = Decimal::money(Decimal::sub($counted, $expected));
            $tolerance = (string) Settings::get('pos.variance_tolerance', $shift->store_id);

            $varianceStatus = 'none';
            if (! Decimal::isZero($variance)) {
                $varianceStatus = 'pending_review';
                if (Decimal::lte(Decimal::abs($variance), $tolerance)) {
                    $varianceStatus = 'approved';
                } elseif ($this->approvals->canSelfApprove($user, 'cash_variance', $shift->store_id) && ($user->id !== $shift->opened_by || $user->isOwner())) {
                    $varianceStatus = 'approved';
                    $shift->variance_reviewed_by = $user->id;
                } elseif ($approvalToken !== null) {
                    $approval = $this->approvals->consume($approvalToken, 'cash_variance', $shift->store_id, $user);
                    $varianceStatus = 'approved';
                    $shift->variance_reviewed_by = $approval->approved_by;
                }
            }

            $zNumber = (int) Shift::query()->where('register_id', $shift->register_id)->max('z_number') + 1;
            $shift->fill([
                'status' => 'closed',
                'closed_by' => $user->id,
                'closed_at' => now(),
                'closing_count' => $this->cleanCount($denominations),
                'counted_cash' => $counted,
                'expected_cash' => $expected,
                'variance' => $variance,
                'variance_status' => $varianceStatus,
                'variance_note' => $note,
                'z_number' => $zNumber,
            ]);
            $report['closing'] = [
                'counted_cash' => $counted, 'expected_cash' => $expected, 'variance' => $variance,
                'variance_status' => $varianceStatus, 'denominations' => $this->cleanCount($denominations),
                'closed_by' => $user->name, 'closed_at' => now()->toIso8601String(), 'z_number' => $zNumber, 'note' => $note,
            ];
            $shift->z_snapshot = $report;
            $shift->save();

            if (! Decimal::isZero($variance)) {
                $this->journals->post('cash_variance', $shift, $shift->business_date->toDateString(), "Cash ".(Decimal::isNegative($variance) ? 'short' : 'over')." on shift {$shift->number}", [
                    ['account' => 'cash_on_hand', 'debit' => $variance],
                    ['account' => 'cash_over_short', 'credit' => $variance],
                ], $shift->store_id);
            }
            Audit::record('shift.closed', "Closed shift {$shift->number} (Z{$zNumber}); counted Rs {$counted}, expected Rs {$expected}, variance Rs {$variance}", $shift);

            return $shift;
        });
    }

    /** A manager reviews a variance after the fact (approve with a note). */
    public function reviewVariance(User $user, Shift $shift, string $note): Shift
    {
        $this->requirePermission($user, 'pos.shift.approve_variance', $shift->store_id);
        if ($shift->variance_status !== 'pending_review') {
            throw new BusinessRuleException('There is no variance waiting for review on this shift.', 'nothing_to_review');
        }
        if ($user->id === $shift->opened_by && ! $user->isOwner()) {
            throw new BusinessRuleException('A cashier cannot approve the variance on their own shift.', 'self_review');
        }
        $shift->update(['variance_status' => 'approved', 'variance_reviewed_by' => $user->id, 'variance_note' => trim(($shift->variance_note ? $shift->variance_note.' | ' : '').$note)]);
        Audit::record('shift.variance_reviewed', "Variance Rs {$shift->variance} on {$shift->number} reviewed", $shift, new: ['note' => $note]);

        return $shift;
    }

    /** @param array<string, int|string> $denominations */
    public function countTotal(array $denominations): string
    {
        $total = '0';
        foreach ($denominations as $value => $count) {
            if ((int) $count < 0) {
                throw new BusinessRuleException('Counts cannot be negative.', 'invalid_count');
            }
            $total = Decimal::add($total, Decimal::mul((string) $value, (string) (int) $count));
        }

        return Decimal::money($total);
    }

    /** @param array<string, int|string> $denominations @return array<string, int> */
    private function cleanCount(array $denominations): array
    {
        $clean = [];
        foreach ($denominations as $value => $count) {
            if ((int) $count > 0) {
                $clean[(string) $value] = (int) $count;
            }
        }
        krsort($clean, SORT_NUMERIC);

        return $clean;
    }

    private function assertOwnOpenShift(User $user, Shift $shift): void
    {
        if ($shift->status !== 'open') {
            throw new BusinessRuleException('This shift is closed.', 'shift_not_open');
        }
        if ($shift->opened_by !== $user->id && ! $user->hasPermission('pos.shift.view_all', $shift->store_id)) {
            throw new BusinessRuleException('This is another cashier\'s shift.', 'shift_other_cashier');
        }
    }

    private function assertCanView(User $user, Shift $shift): void
    {
        if ($shift->opened_by !== $user->id && ! $user->hasPermission('pos.shift.view_all', $shift->store_id)) {
            throw new BusinessRuleException('You can only see your own shifts.', 'shift_not_permitted');
        }
        $this->requirePermission($user, 'pos.reports', $shift->store_id);
    }

    private function requirePermission(User $user, string $permission, int $storeId): void
    {
        if (! $user->hasPermission($permission, $storeId)) {
            throw new BusinessRuleException('You are not allowed to do this.', 'not_permitted', ['permission' => $permission]);
        }
    }
}
