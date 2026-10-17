<?php

namespace App\Domain\Hr;

use App\Exceptions\BusinessRuleException;
use App\Models\Contact;
use App\Models\Holiday;
use App\Models\LeaveAllocation;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\RosterEntry;
use App\Models\User;
use App\Support\Audit;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;

/**
 * Leave requests and balances. Balance = allocation for the year (or the
 * leave type's default) minus approved days. Days on the configured holiday
 * calendar are not counted. Entitlements are company policy maintained by
 * HR; the system does not assume statutory numbers.
 */
final class LeaveService
{
    public function balance(Contact $employee, LeaveType $type, int $year): array
    {
        $allocated = LeaveAllocation::query()->where('contact_id', $employee->id)->where('leave_type_id', $type->id)->where('year', $year)->value('days') ?? $type->default_days_per_year;
        $used = LeaveRequest::query()->where('contact_id', $employee->id)->where('leave_type_id', $type->id)->where('status', 'approved')
            ->whereYear('start_date', $year)->sum('days');
        $pending = LeaveRequest::query()->where('contact_id', $employee->id)->where('leave_type_id', $type->id)->where('status', 'pending')
            ->whereYear('start_date', $year)->sum('days');

        return ['allocated' => (float) $allocated, 'used' => (float) $used, 'pending' => (float) $pending, 'remaining' => (float) $allocated - (float) $used];
    }

    public function request(User $user, Contact $employee, int $leaveTypeId, string $start, string $end, bool $halfDay, ?string $reason): LeaveRequest
    {
        if ($user->employee?->id !== $employee->id && ! $user->hasPermission('hr.leave.approve') && ! $user->hasPermission('hr.employees.manage')) {
            throw new BusinessRuleException('You can only request leave for yourself.', 'not_permitted');
        }
        $type = LeaveType::query()->where('is_active', true)->findOrFail($leaveTypeId);
        $startDate = CarbonImmutable::parse($start);
        $endDate = CarbonImmutable::parse($end);
        if ($endDate->lt($startDate)) {
            throw new BusinessRuleException('The leave ends before it starts.', 'invalid_dates');
        }
        $overlap = LeaveRequest::query()->where('contact_id', $employee->id)->whereIn('status', ['pending', 'approved'])
            ->where('start_date', '<=', $endDate->toDateString())->where('end_date', '>=', $startDate->toDateString())->exists();
        if ($overlap) {
            throw new BusinessRuleException('There is already a leave request for some of these days.', 'leave_overlap');
        }
        $days = $halfDay ? 0.5 : $this->workingDays($employee, $startDate, $endDate);
        if ($days <= 0) {
            throw new BusinessRuleException('These dates are all holidays.', 'no_working_days');
        }
        if ($type->is_paid) {
            $balance = $this->balance($employee, $type, $startDate->year);
            if ($days > $balance['remaining'] - $balance['pending']) {
                throw new BusinessRuleException("Only {$balance['remaining']} days of {$type->name} remain (with {$balance['pending']} pending).", 'insufficient_balance');
            }
        }

        $request = LeaveRequest::create([
            'contact_id' => $employee->id, 'leave_type_id' => $type->id, 'start_date' => $startDate->toDateString(), 'end_date' => $endDate->toDateString(),
            'days' => $days, 'half_day' => $halfDay, 'reason' => $reason, 'status' => 'pending', 'requested_by' => $user->id,
        ]);
        Audit::record('hr.leave_requested', "{$employee->name} requested {$days} days of {$type->name}", $request);

        return $request;
    }

    public function review(User $user, LeaveRequest $request, bool $approve, ?string $note): LeaveRequest
    {
        if (! $user->hasPermission('hr.leave.approve')) {
            throw new BusinessRuleException('You are not allowed to approve leave.', 'not_permitted');
        }
        if ($user->employee?->id === $request->contact_id && ! $user->isOwner()) {
            throw new BusinessRuleException('Someone else must approve your own leave.', 'self_approval');
        }
        if ($request->status !== 'pending') {
            throw new BusinessRuleException('This request was already reviewed.', 'not_pending');
        }
        $request->update(['status' => $approve ? 'approved' : 'rejected', 'reviewed_by' => $user->id, 'reviewed_at' => now(), 'review_note' => $note]);
        $conflicts = $approve ? RosterEntry::query()->where('contact_id', $request->contact_id)->where('status', 'scheduled')
            ->whereBetween('work_date', [$request->start_date->toDateString(), $request->end_date->toDateString()])->count() : 0;
        Audit::record('hr.leave_reviewed', ($approve ? 'Approved' : 'Rejected')." leave of contact #{$request->contact_id}".($conflicts ? " ({$conflicts} rostered shifts need cover)" : ''), $request);

        return $request;
    }

    public function cancel(User $user, LeaveRequest $request): LeaveRequest
    {
        if ($user->employee?->id !== $request->contact_id && ! $user->hasPermission('hr.leave.approve')) {
            throw new BusinessRuleException('You cannot cancel this request.', 'not_permitted');
        }
        $request->update(['status' => 'cancelled']);

        return $request;
    }

    private function workingDays(Contact $employee, CarbonImmutable $start, CarbonImmutable $end): float
    {
        $storeId = $employee->employeeDetail?->store_id;
        $holidays = Holiday::query()->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->where(fn ($q) => $q->whereNull('store_id')->orWhere('store_id', $storeId))
            ->where('type', '!=', 'special_schedule')
            ->pluck('date')->map(fn ($d) => CarbonImmutable::parse($d)->toDateString())->all();
        $days = 0;
        foreach (CarbonPeriod::create($start, $end) as $day) {
            if (! in_array($day->toDateString(), $holidays, true)) {
                $days++;
            }
        }

        return (float) $days;
    }
}
