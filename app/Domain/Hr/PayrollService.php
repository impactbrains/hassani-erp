<?php

namespace App\Domain\Hr;

use App\Domain\Accounting\JournalPoster;
use App\Exceptions\BusinessRuleException;
use App\Models\AdvanceRepayment;
use App\Models\AttendanceRecord;
use App\Models\Contact;
use App\Models\EmployeeAdvance;
use App\Models\LeaveRequest;
use App\Models\PaymentMethod;
use App\Models\PayrollAdjustment;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\PayslipLine;
use App\Models\SalaryStructure;
use App\Models\User;
use App\Support\Audit;
use App\Support\Decimal;
use App\Support\Sequences;
use App\Support\Settings\Settings;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\DB;

/**
 * Monthly payroll:  draft (calculated, can be recalculated) -> approved
 * (by someone other than the preparer) -> posted (journal) -> paid.
 *
 * Per employee:
 *   daily rate      = monthly salary / days basis (calendar, 30 or 26)
 *   unpaid days     = recorded absences + approved unpaid leave
 *   gross           = salary - unpaid days x daily rate + allowances
 *                     + overtime + approved bonuses
 *   deductions      = configured deductions + employee EOBI + approved
 *                     recoveries (only with an investigation reference)
 *                     + due Peshgi installments
 *   net             = gross - deductions (never negative)
 * Statutory rates are not hard-coded: they come from each salary structure.
 */
final class PayrollService
{
    public function __construct(private readonly JournalPoster $journals)
    {
    }

    public function prepare(User $user, string $month, ?int $storeId = null): PayrollRun
    {
        $this->authorize($user, 'hr.payroll.manage');
        $start = CarbonImmutable::parse($month)->startOfMonth();
        $end = $start->endOfMonth();
        $existing = PayrollRun::query()->where('period_start', $start->toDateString())->when($storeId, fn ($q) => $q->where('store_id', $storeId), fn ($q) => $q->whereNull('store_id'))->first();
        if ($existing && $existing->status !== 'draft') {
            throw new BusinessRuleException("Payroll for {$start->format('F Y')} is already {$existing->status}.", 'payroll_locked');
        }

        return DB::transaction(function () use ($user, $start, $end, $storeId, $existing) {
            $run = $existing ?? PayrollRun::create([
                'store_id' => $storeId, 'number' => Sequences::next('payroll_run'), 'period_start' => $start->toDateString(),
                'period_end' => $end->toDateString(), 'status' => 'draft', 'working_days' => $start->daysInMonth, 'created_by' => $user->id,
            ]);
            $run->payslips()->each(fn (Payslip $p) => $p->lines()->delete());
            $run->payslips()->delete();

            $employees = Contact::query()->employees()->with('employeeDetail')
                ->whereHas('employeeDetail', fn ($q) => $q
                    ->where(fn ($w) => $w->whereIn('status', ['onboarding', 'probation', 'active', 'suspended'])
                        ->orWhere(fn ($left) => $left->whereIn('status', ['resigned', 'terminated'])->where('exit_date', '>=', $start->toDateString())))
                    ->when($storeId, fn ($s) => $s->where('store_id', $storeId)))
                ->get();
            $totals = ['gross' => '0', 'deductions' => '0', 'net' => '0', 'employer' => '0'];
            foreach ($employees as $employee) {
                $structure = SalaryStructure::query()->where('contact_id', $employee->id)->where('effective_from', '<=', $end->toDateString())
                    ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $start->toDateString()))
                    ->orderByDesc('effective_from')->first();
                if ($structure === null) {
                    continue;
                }
                $payslip = $this->calculate($run, $employee, $structure, $start, $end);
                $totals['gross'] = Decimal::add($totals['gross'], $payslip->gross_pay);
                $totals['deductions'] = Decimal::add($totals['deductions'], $payslip->total_deductions);
                $totals['net'] = Decimal::add($totals['net'], $payslip->net_pay);
                $totals['employer'] = Decimal::add($totals['employer'], $payslip->employer_contributions);
            }
            $run->update(['gross_total' => Decimal::money($totals['gross']), 'deduction_total' => Decimal::money($totals['deductions']), 'net_total' => Decimal::money($totals['net']), 'employer_cost_total' => Decimal::money(Decimal::add($totals['gross'], $totals['employer']))]);
            Audit::record('hr.payroll_prepared', "Prepared payroll {$run->number} for {$start->format('F Y')}", $run);

            return $run->fresh('payslips.lines');
        });
    }

    private function calculate(PayrollRun $run, Contact $employee, SalaryStructure $structure, CarbonImmutable $start, CarbonImmutable $end): Payslip
    {
        $joined = $employee->employeeDetail?->joining_date;
        $exited = $employee->employeeDetail?->exit_date;
        $periodStart = $joined && $joined->gt($start) ? CarbonImmutable::instance($joined) : $start;
        $periodEnd = $exited && $exited->lt($end) ? CarbonImmutable::instance($exited) : $end;
        $basis = (string) Settings::get('hr.payroll_days_basis');
        $daysBasis = $basis === 'calendar' ? $start->daysInMonth : (int) $basis;

        $absent = AttendanceRecord::query()->where('contact_id', $employee->id)->whereBetween('work_date', [$periodStart->toDateString(), $periodEnd->toDateString()])->where('status', 'absent')->count();
        $halfDays = AttendanceRecord::query()->where('contact_id', $employee->id)->whereBetween('work_date', [$periodStart->toDateString(), $periodEnd->toDateString()])->where('status', 'half_day')->count();
        $lateCount = AttendanceRecord::query()->where('contact_id', $employee->id)->whereBetween('work_date', [$periodStart->toDateString(), $periodEnd->toDateString()])->where('status', 'late')->count();
        $overtime = (int) AttendanceRecord::query()->where('contact_id', $employee->id)->whereBetween('work_date', [$periodStart->toDateString(), $periodEnd->toDateString()])->sum('overtime_minutes');
        [$paidLeave, $unpaidLeave] = $this->leaveDays($employee, $periodStart, $periodEnd);
        $calendarDays = $periodStart->diffInDays($periodEnd) + 1;
        $notEmployedDays = max(0, $start->daysInMonth - $calendarDays);

        $earnings = [];
        $deductions = [];
        $employer = [];
        if ($structure->pay_basis === 'monthly') {
            $daily = Decimal::div($structure->base_amount, (string) $daysBasis, 6);
            $unpaid = Decimal::add((string) ($absent + $unpaidLeave + $notEmployedDays), Decimal::mul('0.5', (string) $halfDays));
            $earnings[] = ['code' => 'BASIC', 'description' => 'Basic salary', 'amount' => $structure->base_amount];
            if (Decimal::isPositive($unpaid)) {
                $earnings[] = ['code' => 'UNPAID_DAYS', 'description' => 'Less unpaid days (absence, unpaid leave, before joining / after leaving)', 'quantity' => $unpaid, 'rate' => $daily, 'amount' => Decimal::money(Decimal::neg(Decimal::mul($daily, $unpaid)))];
            }
        } else {
            $workedDays = AttendanceRecord::query()->where('contact_id', $employee->id)->whereBetween('work_date', [$periodStart->toDateString(), $periodEnd->toDateString()])->whereIn('status', ['present', 'late'])->count() + 0.5 * $halfDays + $paidLeave;
            if ($structure->pay_basis === 'daily') {
                $earnings[] = ['code' => 'WAGES', 'description' => 'Daily wages', 'quantity' => (string) $workedDays, 'rate' => $structure->base_amount, 'amount' => Decimal::money(Decimal::mul($structure->base_amount, (string) $workedDays))];
            } else {
                $hours = Decimal::div((string) AttendanceRecord::query()->where('contact_id', $employee->id)->whereBetween('work_date', [$periodStart->toDateString(), $periodEnd->toDateString()])->sum('worked_minutes'), '60', 2);
                $earnings[] = ['code' => 'WAGES', 'description' => 'Hourly wages', 'quantity' => $hours, 'rate' => $structure->base_amount, 'amount' => Decimal::money(Decimal::mul($structure->base_amount, $hours))];
            }
        }
        foreach ($structure->components ?? [] as $component) {
            $target = ($component['type'] ?? 'allowance') === 'deduction' ? 'deductions' : 'earnings';
            ${$target}[] = ['code' => strtoupper($component['code'] ?? 'COMPONENT'), 'description' => $component['name'] ?? 'Component', 'amount' => Decimal::money($component['amount'] ?? '0')];
        }
        if ($overtime > 0 && $structure->overtime_hourly_rate) {
            $hours = Decimal::div((string) $overtime, '60', 2);
            $earnings[] = ['code' => 'OVERTIME', 'description' => 'Overtime', 'quantity' => $hours, 'rate' => $structure->overtime_hourly_rate, 'amount' => Decimal::money(Decimal::mul($hours, $structure->overtime_hourly_rate))];
        }
        $adjustments = PayrollAdjustment::query()->where('contact_id', $employee->id)->where('status', 'approved')->where('payroll_month', $start->toDateString())->get();
        foreach ($adjustments as $adjustment) {
            $line = ['code' => strtoupper($adjustment->category), 'description' => ucfirst(str_replace('_', ' ', $adjustment->category)).': '.$adjustment->reason, 'amount' => $adjustment->amount, 'source_type' => $adjustment->getMorphClass(), 'source_id' => $adjustment->id];
            in_array($adjustment->type, ['bonus', 'allowance', 'reimbursement'], true) ? $earnings[] = $line : $deductions[] = $line;
        }
        if (Decimal::isPositive($structure->eobi_employee)) {
            $deductions[] = ['code' => 'EOBI', 'description' => 'EOBI (employee share)', 'amount' => $structure->eobi_employee];
        }
        if (Decimal::isPositive($structure->eobi_employer)) {
            $employer[] = ['code' => 'EOBI_ER', 'description' => 'EOBI (employer share)', 'amount' => $structure->eobi_employer];
        }
        if (Decimal::isPositive($structure->social_security_employer)) {
            $employer[] = ['code' => 'SOCIAL_SECURITY_ER', 'description' => 'Social security (employer)', 'amount' => $structure->social_security_employer];
        }

        $gross = Decimal::money(Decimal::max('0', Decimal::sum(array_column($earnings, 'amount'))));
        $available = Decimal::sub($gross, Decimal::sum(array_column($deductions, 'amount')));

        foreach (EmployeeAdvance::query()->where('contact_id', $employee->id)->where('status', 'disbursed')->where('recovery_start_month', '<=', $start->toDateString())->orderBy('id')->get() as $advance) {
            $installment = Decimal::min(Decimal::min($advance->installment_amount ?? $advance->outstanding(), $advance->outstanding()), Decimal::max('0', $available));
            if (Decimal::isPositive($installment)) {
                $deductions[] = ['code' => 'PESHGI', 'description' => "Peshgi installment ({$advance->number})", 'amount' => Decimal::money($installment), 'source_type' => $advance->getMorphClass(), 'source_id' => $advance->id];
                $available = Decimal::sub($available, $installment);
            }
        }
        $totalDeductions = Decimal::money(Decimal::sum(array_column($deductions, 'amount')));
        $net = Decimal::money(Decimal::sub($gross, $totalDeductions));
        if (Decimal::isNegative($net)) {
            throw new BusinessRuleException("Deductions for {$employee->name} are more than their pay this month. Reduce or postpone a deduction.", 'negative_net_pay');
        }

        $payslip = Payslip::create([
            'payroll_run_id' => $run->id, 'contact_id' => $employee->id, 'salary_structure_id' => $structure->id,
            'days_payable' => (string) max(0, $daysBasis - $absent - $unpaidLeave - $notEmployedDays), 'days_present' => (string) ($calendarDays - $absent - $paidLeave - $unpaidLeave),
            'days_absent' => (string) $absent, 'days_paid_leave' => (string) $paidLeave, 'days_unpaid_leave' => (string) $unpaidLeave,
            'late_count' => $lateCount, 'overtime_minutes' => $overtime, 'gross_pay' => $gross, 'total_deductions' => $totalDeductions,
            'net_pay' => $net, 'employer_contributions' => Decimal::money(Decimal::sum(array_column($employer, 'amount'))), 'status' => 'draft',
        ]);
        foreach ([['earning', $earnings], ['deduction', $deductions], ['employer', $employer]] as [$type, $lines]) {
            foreach ($lines as $line) {
                PayslipLine::create(['payslip_id' => $payslip->id, 'type' => $type, 'code' => $line['code'], 'description' => mb_substr($line['description'], 0, 255), 'quantity' => $line['quantity'] ?? null, 'rate' => $line['rate'] ?? null, 'amount' => Decimal::money($line['amount']), 'source_type' => $line['source_type'] ?? null, 'source_id' => $line['source_id'] ?? null]);
            }
        }

        return $payslip;
    }

    public function approve(User $user, PayrollRun $run): PayrollRun
    {
        $this->authorize($user, 'hr.payroll.approve');
        if ($run->status !== 'draft') {
            throw new BusinessRuleException('Only a draft payroll can be approved.', 'invalid_status');
        }
        if ($run->created_by === $user->id && ! $user->isOwner()) {
            throw new BusinessRuleException('Someone other than the preparer must approve payroll.', 'self_approval');
        }
        $run->update(['status' => 'approved', 'approved_by' => $user->id, 'approved_at' => now()]);
        Audit::record('hr.payroll_approved', "Approved payroll {$run->number}", $run);

        return $run;
    }

    /** Posts salary expense and liabilities; records Peshgi recoveries and applies adjustments. */
    public function post(User $user, PayrollRun $run): PayrollRun
    {
        $this->authorize($user, 'hr.payroll.approve');
        if ($run->status !== 'approved') {
            throw new BusinessRuleException('Approve the payroll before posting it.', 'invalid_status');
        }

        return DB::transaction(function () use ($user, $run) {
            $sums = ['salaries_expense' => '0', 'overtime_expense' => '0', 'bonus_expense' => '0', 'employer_contributions' => '0', 'salaries_payable' => '0', 'eobi_payable' => '0', 'social_security_payable' => '0', 'payroll_deductions_payable' => '0'];
            $advanceCredits = [];
            foreach ($run->payslips()->with('lines')->get() as $payslip) {
                foreach ($payslip->lines as $line) {
                    match (true) {
                        $line->type === 'earning' && $line->code === 'OVERTIME' => $sums['overtime_expense'] = Decimal::add($sums['overtime_expense'], $line->amount),
                        $line->type === 'earning' && in_array($line->code, ['BASIC', 'WAGES', 'UNPAID_DAYS'], true) => $sums['salaries_expense'] = Decimal::add($sums['salaries_expense'], $line->amount),
                        $line->type === 'earning' => $sums['bonus_expense'] = Decimal::add($sums['bonus_expense'], $line->amount),
                        $line->code === 'PESHGI' => $advanceCredits[] = [$payslip, $line],
                        $line->code === 'EOBI' => $sums['eobi_payable'] = Decimal::add($sums['eobi_payable'], $line->amount),
                        $line->type === 'employer' && $line->code === 'EOBI_ER' => [$sums['employer_contributions'] = Decimal::add($sums['employer_contributions'], $line->amount), $sums['eobi_payable'] = Decimal::add($sums['eobi_payable'], $line->amount)],
                        $line->type === 'employer' => [$sums['employer_contributions'] = Decimal::add($sums['employer_contributions'], $line->amount), $sums['social_security_payable'] = Decimal::add($sums['social_security_payable'], $line->amount)],
                        default => $sums['payroll_deductions_payable'] = Decimal::add($sums['payroll_deductions_payable'], $line->amount),
                    };
                    if ($line->source_type === (new PayrollAdjustment)->getMorphClass()) {
                        PayrollAdjustment::query()->whereKey($line->source_id)->update(['status' => 'applied', 'payslip_id' => $payslip->id]);
                    }
                }
                $sums['salaries_payable'] = Decimal::add($sums['salaries_payable'], $payslip->net_pay);
                $payslip->update(['status' => 'final']);
            }

            $lines = [
                ['account' => 'salaries_expense', 'debit' => $sums['salaries_expense']],
                ['account' => 'overtime_expense', 'debit' => $sums['overtime_expense']],
                ['account' => 'bonus_expense', 'debit' => $sums['bonus_expense']],
                ['account' => 'employer_contributions', 'debit' => $sums['employer_contributions']],
                ['account' => 'salaries_payable', 'credit' => $sums['salaries_payable']],
                ['account' => 'eobi_payable', 'credit' => $sums['eobi_payable']],
                ['account' => 'social_security_payable', 'credit' => $sums['social_security_payable']],
                ['account' => 'payroll_deductions_payable', 'credit' => $sums['payroll_deductions_payable']],
            ];
            foreach ($advanceCredits as [$payslip, $line]) {
                $lines[] = ['account' => 'employee_advances', 'credit' => $line->amount, 'contact_id' => $payslip->contact_id];
                $advance = EmployeeAdvance::query()->lockForUpdate()->find($line->source_id);
                AdvanceRepayment::create(['employee_advance_id' => $advance->id, 'amount' => $line->amount, 'source' => 'payroll', 'payslip_id' => $payslip->id, 'repaid_on' => $run->period_end->toDateString(), 'recorded_by' => $user->id]);
                $advance->recovered_amount = Decimal::money(Decimal::add($advance->recovered_amount, $line->amount));
                $advance->status = Decimal::gte($advance->recovered_amount, $advance->amount) ? 'settled' : 'disbursed';
                $advance->save();
            }
            $this->journals->post('payroll', $run, $run->period_end->toDateString(), "Payroll {$run->number}", $lines, $run->store_id);
            $run->update(['status' => 'posted', 'posted_by' => $user->id, 'posted_at' => now()]);
            Audit::record('hr.payroll_posted', "Posted payroll {$run->number}: net Rs {$run->net_total}", $run);

            return $run;
        });
    }

    /** @param list<int>|null $payslipIds */
    public function markPaid(User $user, PayrollRun $run, int $paymentMethodId, ?string $reference, ?array $payslipIds = null): PayrollRun
    {
        $this->authorize($user, 'hr.payroll.approve');
        if (! in_array($run->status, ['posted', 'paid'], true)) {
            throw new BusinessRuleException('Post the payroll before paying it.', 'invalid_status');
        }
        $method = PaymentMethod::query()->findOrFail($paymentMethodId);

        return DB::transaction(function () use ($user, $run, $method, $reference, $payslipIds) {
            $payslips = $run->payslips()->where('status', 'final')->when($payslipIds, fn ($q) => $q->whereIn('id', $payslipIds))->lockForUpdate()->get();
            $total = '0';
            foreach ($payslips as $payslip) {
                $payslip->update(['status' => 'paid', 'payment_method_id' => $method->id, 'payment_reference' => $reference, 'paid_at' => now()]);
                $total = Decimal::add($total, $payslip->net_pay);
            }
            $date = now()->setTimezone('Asia/Karachi')->toDateString();
            $this->journals->post('pay_'.substr(md5(implode(',', $payslips->pluck('id')->all())), 0, 20), $run, $date, "Salaries paid for {$run->number} by {$method->name}", [
                ['account' => 'salaries_payable', 'debit' => $total],
                ['account' => $method->account_id, 'credit' => $total],
            ], $run->store_id);
            if ($run->payslips()->where('status', '!=', 'paid')->doesntExist()) {
                $run->update(['status' => 'paid']);
            }
            Audit::record('hr.payroll_paid', "Paid Rs ".Decimal::money($total)." of payroll {$run->number}", $run);

            return $run;
        });
    }

    /** @return array{0: float, 1: float} paid and unpaid approved leave days within the period */
    private function leaveDays(Contact $employee, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $paid = 0.0;
        $unpaid = 0.0;
        $requests = LeaveRequest::query()->with('leaveType')->where('contact_id', $employee->id)->where('status', 'approved')
            ->where('start_date', '<=', $end->toDateString())->where('end_date', '>=', $start->toDateString())->get();
        foreach ($requests as $request) {
            if ($request->half_day) {
                $days = 0.5;
            } else {
                $overlapStart = max($request->start_date->toDateString(), $start->toDateString());
                $overlapEnd = min($request->end_date->toDateString(), $end->toDateString());
                $days = (float) iterator_count(CarbonPeriod::create($overlapStart, $overlapEnd));
                $days = min($days, (float) $request->days);
            }
            $request->leaveType->is_paid ? $paid += $days : $unpaid += $days;
        }

        return [$paid, $unpaid];
    }

    private function authorize(User $user, string $permission): void
    {
        if (! $user->hasPermission($permission)) {
            throw new BusinessRuleException('You are not allowed to work on payroll.', 'not_permitted');
        }
    }
}
