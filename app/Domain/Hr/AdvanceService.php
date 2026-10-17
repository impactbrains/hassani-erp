<?php

namespace App\Domain\Hr;

use App\Domain\Accounting\JournalPoster;
use App\Exceptions\BusinessRuleException;
use App\Models\AdvanceRepayment;
use App\Models\CashMovement;
use App\Models\Contact;
use App\Models\EmployeeAdvance;
use App\Models\PaymentMethod;
use App\Models\PayrollAdjustment;
use App\Models\Shift;
use App\Models\User;
use App\Support\Audit;
use App\Support\Decimal;
use App\Support\Sequences;
use App\Support\Settings\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Peshgi (employee advances) and one-off payroll adjustments.
 *
 * Advance: requested -> approved (not by the requester) -> disbursed
 * (Dr employee advances, Cr cash/bank; from a POS counter shift when paid at the
 * counter) -> recovered by payroll installments or cash repayments -> settled.
 *
 * Deductions for shortages or damage need an approved investigation
 * reference; a manager typing a number is never enough to cut wages.
 */
final class AdvanceService
{
    public function __construct(private readonly JournalPoster $journals)
    {
    }

    public function request(User $user, Contact $employee, string $amount, int $installments, string $recoveryStartMonth, ?string $reason): EmployeeAdvance
    {
        if ($user->employee?->id !== $employee->id && ! $user->hasPermission('hr.advances.manage')) {
            throw new BusinessRuleException('You can only request an advance for yourself.', 'not_permitted');
        }
        $max = (int) Settings::get('hr.max_advance_installments');
        if ($installments < 1 || $installments > $max) {
            throw new BusinessRuleException("Repay in 1 to {$max} installments.", 'invalid_installments');
        }
        $amount = Decimal::money($amount);
        if (! Decimal::isPositive($amount)) {
            throw new BusinessRuleException('Enter the amount.', 'amount_required');
        }
        $advance = EmployeeAdvance::create([
            'contact_id' => $employee->id, 'number' => Sequences::next('employee_advance'), 'amount' => $amount, 'reason' => $reason,
            'status' => 'requested', 'installment_amount' => Decimal::money(Decimal::div($amount, (string) $installments, 2)),
            'recovery_start_month' => CarbonImmutable::parse($recoveryStartMonth)->startOfMonth()->toDateString(), 'requested_by' => $user->id,
        ]);
        Audit::record('hr.advance_requested', "{$employee->name} requested Peshgi of Rs {$amount}", $advance);

        return $advance;
    }

    public function review(User $user, EmployeeAdvance $advance, bool $approve, ?string $note = null): EmployeeAdvance
    {
        $this->authorize($user);
        if ($advance->status !== 'requested') {
            throw new BusinessRuleException('This advance was already reviewed.', 'not_pending');
        }
        if (($advance->requested_by === $user->id || $user->employee?->id === $advance->contact_id) && ! $user->isOwner()) {
            throw new BusinessRuleException('Someone else must approve this advance.', 'self_approval');
        }
        $advance->update(['status' => $approve ? 'approved' : 'rejected', 'approved_by' => $user->id, 'approved_at' => now(), 'note' => $note]);
        Audit::record($approve ? 'hr.advance_approved' : 'hr.advance_rejected', ($approve ? 'Approved' : 'Rejected')." Peshgi {$advance->number}", $advance);

        return $advance;
    }

    public function disburse(User $user, EmployeeAdvance $advance, int $paymentMethodId, ?int $shiftId = null): EmployeeAdvance
    {
        $this->authorize($user);

        return DB::transaction(function () use ($user, $advance, $paymentMethodId, $shiftId) {
            $advance = EmployeeAdvance::query()->lockForUpdate()->findOrFail($advance->id);
            if ($advance->status !== 'approved') {
                throw new BusinessRuleException('Only an approved advance can be paid out.', 'invalid_status');
            }
            $method = PaymentMethod::query()->findOrFail($paymentMethodId);
            $date = now()->setTimezone('Asia/Karachi')->toDateString();
            if ($method->type === 'cash' && $shiftId) {
                $shift = Shift::query()->where('status', 'open')->findOrFail($shiftId);
                $available = app(\App\Domain\Pos\ShiftReport::class)->expectedCash($shift);
                if (Decimal::gt($advance->amount, $available)) {
                    throw new BusinessRuleException("The drawer should only hold Rs {$available}.", 'not_enough_cash');
                }
                CashMovement::create([
                    'store_id' => $shift->store_id, 'shift_id' => $shift->id, 'number' => Sequences::next('cash_movement', $shift->store_id, $shift->store->code),
                    'type' => 'payout', 'amount' => $advance->amount, 'recipient' => $advance->contact->name, 'note' => "Peshgi {$advance->number}",
                    'user_id' => $user->id, 'approved_by' => $advance->approved_by, 'occurred_at' => now(), 'business_date' => $shift->store->businessDate(),
                ]);
                $date = $shift->store->businessDate();
            }
            $this->journals->post('advance_disbursed', $advance, $date, "Peshgi {$advance->number} paid to {$advance->contact->name}", [
                ['account' => 'employee_advances', 'debit' => $advance->amount, 'contact_id' => $advance->contact_id],
                ['account' => $method->account_id, 'credit' => $advance->amount],
            ]);
            $advance->update(['status' => 'disbursed', 'disbursed_by' => $user->id, 'disbursed_at' => now(), 'payment_method_id' => $method->id, 'business_date' => $date]);
            Audit::record('hr.advance_disbursed', "Paid Peshgi {$advance->number} (Rs {$advance->amount})", $advance);

            return $advance;
        });
    }

    public function repayInCash(User $user, EmployeeAdvance $advance, string $amount, int $paymentMethodId): AdvanceRepayment
    {
        $this->authorize($user);

        return DB::transaction(function () use ($user, $advance, $amount, $paymentMethodId) {
            $advance = EmployeeAdvance::query()->lockForUpdate()->findOrFail($advance->id);
            $amount = Decimal::money($amount);
            if ($advance->status !== 'disbursed' || Decimal::gt($amount, $advance->outstanding()) || ! Decimal::isPositive($amount)) {
                throw new BusinessRuleException('Enter an amount up to the outstanding Rs '.$advance->outstanding().'.', 'invalid_amount');
            }
            $method = PaymentMethod::query()->findOrFail($paymentMethodId);
            $repayment = AdvanceRepayment::create(['employee_advance_id' => $advance->id, 'amount' => $amount, 'source' => 'cash', 'payment_method_id' => $method->id, 'repaid_on' => now()->toDateString(), 'recorded_by' => $user->id]);
            $this->journals->post('advance_repayment', $repayment, now()->toDateString(), "Peshgi {$advance->number} repaid", [
                ['account' => $method->account_id, 'debit' => $amount],
                ['account' => 'employee_advances', 'credit' => $amount, 'contact_id' => $advance->contact_id],
            ]);
            $advance->recovered_amount = Decimal::money(Decimal::add($advance->recovered_amount, $amount));
            $advance->status = Decimal::gte($advance->recovered_amount, $advance->amount) ? 'settled' : 'disbursed';
            $advance->save();

            return $repayment;
        });
    }

    /** @param array{contact_id: int, type: string, category: string, amount: string, payroll_month: string, reason: string, investigation_reference?: string|null, evidence_media_id?: int|null} $data */
    public function requestAdjustment(User $user, array $data): PayrollAdjustment
    {
        if (! $user->hasPermission('hr.payroll.manage')) {
            throw new BusinessRuleException('You are not allowed to add payroll adjustments.', 'not_permitted');
        }
        if (in_array($data['category'], ['shortage_recovery', 'damage_recovery'], true) && empty($data['investigation_reference'])) {
            throw new BusinessRuleException('A recovery from wages needs the reference of a completed investigation (HR case).', 'investigation_required', field: 'investigation_reference');
        }

        return PayrollAdjustment::create($data + ['status' => 'pending', 'requested_by' => $user->id, 'payroll_month' => CarbonImmutable::parse($data['payroll_month'])->startOfMonth()->toDateString()]);
    }

    public function reviewAdjustment(User $user, PayrollAdjustment $adjustment, bool $approve): PayrollAdjustment
    {
        if (! $user->hasPermission('hr.payroll.approve')) {
            throw new BusinessRuleException('You are not allowed to approve payroll adjustments.', 'not_permitted');
        }
        if ($adjustment->requested_by === $user->id && ! $user->isOwner()) {
            throw new BusinessRuleException('Someone else must approve this adjustment.', 'self_approval');
        }
        $adjustment->update(['status' => $approve ? 'approved' : 'rejected', 'approved_by' => $user->id, 'approved_at' => now()]);
        Audit::record('hr.adjustment_reviewed', ($approve ? 'Approved' : 'Rejected')." {$adjustment->category} of Rs {$adjustment->amount} for contact #{$adjustment->contact_id}", $adjustment);

        return $adjustment;
    }

    private function authorize(User $user): void
    {
        if (! $user->hasPermission('hr.advances.approve')) {
            throw new BusinessRuleException('You are not allowed to approve or pay advances.', 'not_permitted');
        }
    }
}
