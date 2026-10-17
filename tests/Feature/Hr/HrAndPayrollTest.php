<?php

use App\Domain\Hr\AdvanceService;
use App\Domain\Hr\AttendanceService;
use App\Domain\Hr\EmployeeService;
use App\Domain\Hr\LeaveService;
use App\Domain\Hr\PayrollService;
use App\Domain\Identity\UserAccountService;
use App\Exceptions\BusinessRuleException;
use App\Models\AttendanceRecord;
use App\Models\LeaveType;
use App\Models\PaymentMethod;
use App\Models\Role;
use App\Models\SalaryStructure;
use App\Models\User;
use App\Models\WorkShift;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->setUpCompany();
    $this->owner = $this->userWithRole('owner');
    $this->hr = $this->userWithRole('hr_manager');
    $this->employees = app(EmployeeService::class);
    $this->employee = $this->employees->save($this->owner, ['name' => 'Ayesha Khan', 'role_type' => 'cashier', 'store_id' => $this->store->id, 'joining_date' => '2026-01-01', 'cnic' => '42101-1234567-1']);
});

it('keeps CNIC encrypted, refuses duplicates and hides it without permission', function () {
    $raw = DB::table('employee_details')->where('contact_id', $this->employee->id)->value('cnic');
    expect($raw)->not->toContain('1234567');
    expect($this->employee->employeeDetail->cnic)->toBe('42101-1234567-1');

    expect(fn () => DB::transaction(fn () => $this->employees->save($this->owner, ['name' => 'Copy', 'cnic' => '4210112345671'])))->toThrow(BusinessRuleException::class, 'already has this CNIC');
    $manager = $this->userWithRole('store_manager', $this->store);
    expect(fn () => $this->employees->save($manager, ['cnic' => '4210112345672'], $this->employee))->toThrow(BusinessRuleException::class);
});

it('marks late arrivals after the grace period and counts overtime', function () {
    $shift = WorkShift::query()->where('name', 'Morning')->firstOrFail(); // 09:00-17:00, 30 min break
    $attendance = app(AttendanceService::class);
    $attendance->assignShift($this->hr, $this->employee, $shift->id, '2026-10-12');
    $tz = 'Asia/Karachi';

    $attendance->clockIn($this->hr, $this->employee, CarbonImmutable::parse('2026-10-12 09:25', $tz));
    $record = $attendance->clockOut($this->hr, $this->employee, CarbonImmutable::parse('2026-10-12 18:30', $tz));

    expect($record->status)->toBe('late')
        ->and($record->late_minutes)->toBe(25)
        ->and($record->overtime_minutes)->toBe(60) // 90 minutes after 17:00, less 30 minutes buffer
        ->and($record->worked_minutes)->toBe(515);
});

it('refuses overlapping roster shifts and shifts during approved leave', function () {
    $attendance = app(AttendanceService::class);
    $morning = WorkShift::query()->where('name', 'Morning')->first();
    $evening = WorkShift::query()->where('name', 'Evening')->first();
    $attendance->assignShift($this->hr, $this->employee, $morning->id, '2026-10-13');
    expect(fn () => DB::transaction(fn () => $attendance->assignShift($this->hr, $this->employee, $evening->id, '2026-10-13')))->toThrow(BusinessRuleException::class, 'overlaps');

    $leave = app(LeaveService::class)->request($this->hr, $this->employee, LeaveType::query()->where('code', 'CASUAL')->value('id'), '2026-10-20', '2026-10-21', false, 'Wedding');
    app(LeaveService::class)->review($this->owner, $leave, true, null);
    expect(fn () => $attendance->assignShift($this->hr, $this->employee, $morning->id, '2026-10-20'))->toThrow(BusinessRuleException::class, 'approved leave');
});

it('runs payroll with unpaid absences, a bonus and a Peshgi installment, then posts and pays it', function () {
    SalaryStructure::create(['contact_id' => $this->employee->id, 'effective_from' => '2026-01-01', 'pay_basis' => 'monthly', 'base_amount' => '31000', 'components' => [['code' => 'FUEL', 'name' => 'Fuel allowance', 'type' => 'allowance', 'amount' => '2000']], 'eobi_employee' => '370', 'eobi_employer' => '1850']);
    AttendanceRecord::create(['contact_id' => $this->employee->id, 'work_date' => '2026-10-07', 'status' => 'absent']);

    $advances = app(AdvanceService::class);
    $advance = $advances->request($this->hr, $this->employee, '6000', 3, '2026-10-01', 'Medical');
    $advances->review($this->owner, $advance, true);
    $cash = PaymentMethod::query()->where('code', 'cash')->firstOrFail();
    $advances->disburse($this->owner, $advance->fresh(), $cash->id);

    $bonus = $advances->requestAdjustment($this->hr, ['contact_id' => $this->employee->id, 'type' => 'bonus', 'category' => 'eid_bonus', 'amount' => '5000', 'payroll_month' => '2026-10-01', 'reason' => 'Eid ul Adha']);
    $advances->reviewAdjustment($this->owner, $bonus, true);
    expect(fn () => $advances->requestAdjustment($this->hr, ['contact_id' => $this->employee->id, 'type' => 'deduction', 'category' => 'shortage_recovery', 'amount' => '500', 'payroll_month' => '2026-10-01', 'reason' => 'Till short']))
        ->toThrow(BusinessRuleException::class, 'investigation');

    $payroll = app(PayrollService::class);
    $run = $payroll->prepare($this->hr, '2026-10-01');
    $slip = $run->payslips->first();
    // 31,000 - 1 day (31,000 / 31) + 2,000 fuel + 5,000 bonus = 37,000
    expect($slip->gross_pay)->toBe('37000.00')
        ->and($slip->total_deductions)->toBe('2370.00') // EOBI 370 + Peshgi 2,000
        ->and($slip->net_pay)->toBe('34630.00');

    expect(fn () => $payroll->approve($this->hr, $run))->toThrow(BusinessRuleException::class);
    $payroll->approve($this->owner, $run);
    $payroll->post($this->owner, $run->fresh());
    expect($advance->fresh()->recovered_amount)->toBe('2000.00');
    expect($this->accountBalance('employee_advances', $this->employee->id))->toBe('4000.00')
        ->and($this->accountBalance('salaries_payable'))->toBe('-34630.00');

    $payroll->markPaid($this->owner, $run->fresh(), PaymentMethod::query()->where('code', 'bank_transfer')->value('id'), 'IBFT batch 10');
    expect($run->fresh()->status)->toBe('paid')->and($this->accountBalance('salaries_payable'))->toBe('0.00');
    $this->assertLedgerBalanced();
});

it('deactivates the linked login when an employee leaves, without deleting history', function () {
    $created = app(UserAccountService::class)->create($this->owner, ['name' => 'Ayesha', 'email' => 'ayesha@example.test', 'employee_id' => $this->employee->id], [['role_id' => Role::query()->where('key', 'cashier')->value('id'), 'store_id' => $this->store->id]]);
    $login = $created['user'];
    $login->createToken('rider-app');
    DB::table('sessions')->insert(['id' => 'sess-1', 'user_id' => $login->id, 'payload' => 'x', 'last_activity' => time()]);

    $this->employees->transition($this->hr, $this->employee->fresh(), 'resign', '2026-10-31', 'Moved to Lahore');

    expect($login->fresh()->status)->toBe(User::STATUS_DEACTIVATED)
        ->and($login->tokens()->count())->toBe(0)
        ->and(DB::table('sessions')->where('user_id', $login->id)->count())->toBe(0)
        ->and($this->employee->fresh()->employeeDetail->status)->toBe('resigned');
    expect($login->fresh()->hasPermission('pos.sell', $this->store->id))->toBeFalse();
});
