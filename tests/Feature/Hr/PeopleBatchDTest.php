<?php

use App\Domain\Hr\AttendanceService;
use App\Domain\Hr\EmployeeExportService;
use App\Domain\Hr\EmployeeService;
use App\Domain\Identity\UserAccountService;
use App\Domain\Identity\UserPermissionService;
use App\Exceptions\BusinessRuleException;
use App\Models\AttendanceBreak;
use App\Models\AttendanceRecord;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Models\UserPermissionOverride;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    $this->setUpCompany();
    $this->owner = $this->userWithRole('owner');
    $this->hr = $this->userWithRole('hr_manager');
    $this->employees = app(EmployeeService::class);
    $this->attendance = app(AttendanceService::class);
    $this->employee = $this->employees->save($this->owner, ['name' => 'Bilal Raza', 'role_type' => 'cashier', 'store_id' => $this->store->id, 'joining_date' => '2026-01-01', 'email' => 'bilal@example.test']);
    $this->tz = 'Asia/Karachi';
});

it('totals breaks, flags time over the allowed minutes and remembers who entered each one', function () {
    $this->attendance->clockIn($this->hr, $this->employee, CarbonImmutable::parse('2026-10-12 09:00', $this->tz));
    $meal = $this->attendance->addBreak($this->hr, $this->employee, '2026-10-12', 'meal', '13:00', '14:15', false, null);
    $prayer = $this->attendance->addBreak($this->hr, $this->employee, '2026-10-12', 'prayer', '15:00', '15:20', true, null);

    $record = AttendanceRecord::query()->where('contact_id', $this->employee->id)->firstOrFail();
    expect($meal->minutes)->toBe(75)->and($prayer->minutes)->toBe(20)
        ->and($meal->entered_by)->toBe($this->hr->id)->and($meal->entered_by_role)->toBe('manager')
        ->and($record->break_minutes)->toBe(95)
        ->and($record->unpaid_break_minutes)->toBe(20)
        ->and($record->break_over_minutes)->toBe(35); // default allowance is 60 minutes
});

it('records an employee starting and ending their own break and closes an open one at clock-out', function () {
    $login = $this->userWithRole('cashier', $this->store);
    app(UserAccountService::class)->linkEmployee($this->owner, $login, $this->employee->id);
    $login = $login->fresh();
    $this->attendance->clockIn($login, $this->employee, CarbonImmutable::now()->subHours(4));

    $break = $this->attendance->startBreak($login, $this->employee, 'meal');
    expect($break->entered_by_role)->toBe('employee');
    expect(fn () => $this->attendance->startBreak($login, $this->employee, 'prayer'))->toThrow(BusinessRuleException::class, 'already on a break');

    $record = $this->attendance->clockOut($login, $this->employee);
    expect($break->fresh()->ended_at)->not->toBeNull()->and($record->clock_out_at)->not->toBeNull();
});

it('refuses overlapping breaks and breaks outside the clocked hours', function () {
    $this->attendance->clockIn($this->hr, $this->employee, CarbonImmutable::parse('2026-10-12 09:00', $this->tz));
    $this->attendance->clockOut($this->hr, $this->employee, CarbonImmutable::parse('2026-10-12 17:00', $this->tz));
    $this->attendance->addBreak($this->hr, $this->employee, '2026-10-12', 'meal', '13:00', '13:30', false, null);

    expect(fn () => DB::transaction(fn () => $this->attendance->addBreak($this->hr, $this->employee, '2026-10-12', 'other', '13:15', '13:45', false, null)))->toThrow(BusinessRuleException::class, 'overlaps');
    expect(fn () => DB::transaction(fn () => $this->attendance->addBreak($this->hr, $this->employee, '2026-10-12', 'other', '17:30', '17:45', false, null)))->toThrow(BusinessRuleException::class, 'outside');
    expect(fn () => $this->attendance->addBreak($this->hr, $this->employee, '2026-10-12', 'nap', '10:00', '10:10', false, null))->toThrow(BusinessRuleException::class);
});

it('removing a break updates the day and only managers can remove it', function () {
    $this->attendance->clockIn($this->hr, $this->employee, CarbonImmutable::parse('2026-10-12 09:00', $this->tz));
    $break = $this->attendance->addBreak($this->hr, $this->employee, '2026-10-12', 'meal', '13:00', '14:30', false, null);
    $cashier = $this->userWithRole('cashier', $this->store);
    expect(fn () => $this->attendance->deleteBreak($cashier, $break))->toThrow(BusinessRuleException::class);

    $this->attendance->deleteBreak($this->hr, $break);
    expect(AttendanceBreak::query()->count())->toBe(0)->and(AttendanceRecord::query()->first()->break_minutes)->toBe(0);
});

it('creates a login for an employee with a chosen temporary password that must be changed, and switches it off when they leave', function () {
    $accounts = app(UserAccountService::class);
    $role = Role::query()->where('key', 'cashier')->firstOrFail();
    $result = $accounts->create($this->owner, ['name' => $this->employee->name, 'email' => 'bilal@example.test', 'employee_id' => $this->employee->id, 'temporary_password' => 'Temp-Pass-2026'], [['role_id' => $role->id, 'store_id' => $this->store->id]]);

    $user = $result['user']->fresh();
    expect($result['temporary_password'])->toBe('Temp-Pass-2026')
        ->and(Hash::check('Temp-Pass-2026', $user->password))->toBeTrue()
        ->and($user->must_change_password)->toBeTrue()
        ->and($this->employee->fresh()->user_id)->toBe($user->id);

    $this->employees->transition($this->hr, $this->employee->fresh(), 'terminate', '2026-10-15', 'Left the job', 'Reliable at the till, weak at stock counts.');
    expect($user->fresh()->status)->toBe(User::STATUS_DEACTIVATED)
        ->and($this->employee->fresh()->employeeDetail->employer_insights)->toContain('Reliable');

    $this->employees->transition($this->hr, $this->employee->fresh(), 'rehire', '2026-11-01');
    $detail = $this->employee->fresh()->employeeDetail;
    expect($user->fresh()->status)->toBe(User::STATUS_ACTIVE)
        ->and($detail->status)->toBe('onboarding')
        ->and($detail->joining_date->toDateString())->toBe('2026-11-01')
        ->and($detail->exit_date)->toBeNull();
});

it('gives and takes away single permissions, per store, and resets to the role default', function () {
    $service = app(UserPermissionService::class);
    $second = Store::create(['code' => 'T2', 'name' => 'Second branch', 'is_active' => true]);
    $cashier = $this->userWithRole('cashier'); // every store
    expect($cashier->hasPermission('catalog.costs.view'))->toBeFalse();

    $service->set($this->owner, $cashier, 'catalog.costs.view', 'grant', null, 'Covers purchasing');
    expect($cashier->fresh()->hasPermission('catalog.costs.view'))->toBeTrue();

    $service->set($this->owner, $cashier, 'pos.refund', 'revoke', $second->id, null);
    $fresh = $cashier->fresh();
    expect($fresh->hasPermission('pos.refund', $this->store))->toBeTrue()
        ->and($fresh->hasPermission('pos.refund', $second))->toBeFalse()
        ->and($fresh->storeIdsFor('pos.refund'))->not->toContain($second->id);

    $service->set($this->owner, $cashier, 'pos.refund', 'revoke', null, null);
    expect($cashier->fresh()->hasPermission('pos.refund'))->toBeFalse();

    expect($service->reset($this->owner, $cashier))->toBe(3);
    $back = $cashier->fresh();
    expect($back->hasPermission('catalog.costs.view'))->toBeFalse()->and($back->hasPermission('pos.refund'))->toBeTrue()
        ->and(UserPermissionOverride::query()->count())->toBe(0);
});

it('stops people giving permissions they do not hold, changing their own, or restricting an owner', function () {
    $service = app(UserPermissionService::class);
    $admin = $this->userWithRole('owner');
    $manager = $this->userWithRole('store_manager', $this->store);
    $service->set($admin, $manager, 'settings.users.permissions', 'grant', null, null); // lets the manager manage permissions
    $manager = $manager->fresh();
    $cashier = $this->userWithRole('cashier', $this->store);

    expect(fn () => $service->set($manager, $cashier, 'settings.company.manage', 'grant', null, null))->toThrow(BusinessRuleException::class, 'cannot give');
    expect(fn () => $service->set($manager, $manager, 'pos.refund', 'revoke', null, null))->toThrow(BusinessRuleException::class, 'your own');
    expect(fn () => $service->set($manager, $admin, 'pos.refund', 'revoke', null, null))->toThrow(BusinessRuleException::class, 'owner');
    expect(fn () => $service->set($cashier, $manager, 'pos.refund', 'revoke', null, null))->toThrow(BusinessRuleException::class, 'not allowed');
});

it('exports the latest employment period to Excel and leaves out what the exporter may not see', function () {
    $this->attendance->clockIn($this->hr, $this->employee, CarbonImmutable::parse('2026-10-12 09:00', $this->tz));
    $this->attendance->addBreak($this->hr, $this->employee, '2026-10-12', 'meal', '13:00', '13:30', false, null);
    $this->employees->transition($this->hr, $this->employee->fresh(), 'resign', '2026-10-20', 'Moving cities', 'Would rehire.');

    $data = app(EmployeeExportService::class)->build($this->owner, $this->employee->fresh());
    $keys = array_column($data['sections'], 'key');
    expect($data['period'])->toBe(['from' => '2026-01-01', 'to' => '2026-10-20'])
        ->and($keys)->toContain('summary', 'insights', 'attendance', 'breaks', 'payroll', 'assets')
        ->and(collect($data['sections'])->firstWhere('key', 'insights')['rows'][0][2])->toBe('Would rehire.')
        ->and(collect($data['sections'])->firstWhere('key', 'breaks')['rows'])->toHaveCount(1);

    $path = app(EmployeeExportService::class)->toXlsx($data);
    $zip = new ZipArchive;
    expect($zip->open($path))->toBeTrue()->and($zip->locateName('xl/workbook.xml'))->not->toBeFalse();
    $zip->close();
    unlink($path);

    $payrollOnly = $this->userWithRole('store_manager', $this->store);
    expect(fn () => app(EmployeeExportService::class)->build($payrollOnly, $this->employee->fresh()))->toThrow(BusinessRuleException::class);
});
