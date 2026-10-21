<?php

namespace App\Domain\Hr;

use App\Exceptions\BusinessRuleException;
use App\Models\AttendanceBreak;
use App\Models\AttendanceCorrection;
use App\Models\AttendanceRecord;
use App\Models\Contact;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\RosterEntry;
use App\Models\Store;
use App\Models\User;
use App\Models\WorkShift;
use App\Support\Audit;
use App\Support\Settings\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Rosters, clock-in/out and attendance rules (all times in the store's
 * time zone, stored as UTC):
 *   late         = clock-in after shift start + grace minutes
 *   early leave  = clock-out before shift end
 *   worked       = clock-out - clock-in - break
 *   overtime     = time after shift end + the overtime buffer
 *   half day     = worked below the half-day threshold
 * Corrections go through a request and approval; the original values are
 * kept. A device failure never becomes an automatic penalty: missing punches
 * show as gaps to correct, not as fines.
 */
final class AttendanceService
{
    public function assignShift(User $user, Contact $employee, int $workShiftId, string $date, bool $ignoreLeave = false): RosterEntry
    {
        if (! $user->hasPermission('hr.attendance.manage')) {
            throw new BusinessRuleException('You are not allowed to manage rosters.', 'not_permitted');
        }
        $shift = WorkShift::query()->findOrFail($workShiftId);
        $storeId = $employee->employeeDetail?->store_id ?? $employee->store_id ?? Store::query()->value('id');
        foreach (RosterEntry::query()->with('workShift')->where('contact_id', $employee->id)->where('work_date', $date)->where('status', 'scheduled')->get() as $existing) {
            [$aStart, $aEnd] = $this->window($date, $existing->workShift);
            [$bStart, $bEnd] = $this->window($date, $shift);
            if ($aStart->lt($bEnd) && $bStart->lt($aEnd)) {
                throw new BusinessRuleException("{$employee->name} already works the {$existing->workShift->name} shift on {$date}, which overlaps.", 'roster_overlap');
            }
        }
        $onLeave = LeaveRequest::query()->where('contact_id', $employee->id)->where('status', 'approved')->where('start_date', '<=', $date)->where('end_date', '>=', $date)->exists();
        if ($onLeave && ! $ignoreLeave) {
            throw new BusinessRuleException("{$employee->name} is on approved leave on {$date}.", 'leave_conflict');
        }

        return RosterEntry::create(['store_id' => $storeId, 'contact_id' => $employee->id, 'work_shift_id' => $shift->id, 'work_date' => $date, 'status' => 'scheduled', 'created_by' => $user->id]);
    }

    public function clockIn(User $user, Contact $employee, ?\DateTimeInterface $at = null, string $source = 'web', ?string $device = null): AttendanceRecord
    {
        $this->authorizePunch($user, $employee);
        // Eloquent writes datetimes without an offset: always hand it UTC.
        $at = CarbonImmutable::instance($at ?? now())->utc();
        $date = $at->setTimezone($this->timezone($employee))->toDateString();

        return DB::transaction(function () use ($employee, $at, $date, $source, $device) {
            $record = AttendanceRecord::query()->where('contact_id', $employee->id)->where('work_date', $date)->lockForUpdate()->first()
                ?? new AttendanceRecord(['contact_id' => $employee->id, 'work_date' => $date, 'store_id' => $employee->employeeDetail?->store_id]);
            if ($record->clock_in_at) {
                throw new BusinessRuleException("{$employee->name} already clocked in today at ".$record->clock_in_at->setTimezone($this->timezone($employee))->format('H:i').'.', 'already_clocked_in');
            }
            $record->fill(['clock_in_at' => $at, 'clock_in_source' => $source, 'device_label' => $device]);
            $this->evaluate($record, $employee);
            $record->save();

            return $record;
        });
    }

    public function clockOut(User $user, Contact $employee, ?\DateTimeInterface $at = null, string $source = 'web'): AttendanceRecord
    {
        $this->authorizePunch($user, $employee);
        $at = CarbonImmutable::instance($at ?? now())->utc();

        return DB::transaction(function () use ($user, $employee, $at, $source) {
            $record = AttendanceRecord::query()->where('contact_id', $employee->id)->whereNotNull('clock_in_at')->whereNull('clock_out_at')
                ->orderByDesc('work_date')->lockForUpdate()->first();
            if ($record === null) {
                throw new BusinessRuleException("{$employee->name} has not clocked in.", 'not_clocked_in');
            }
            $open = AttendanceBreak::query()->where('contact_id', $employee->id)->whereNull('ended_at')->lockForUpdate()->get();
            foreach ($open as $break) {
                $end = $at->lt($break->started_at) ? CarbonImmutable::instance($break->started_at) : $at;
                $break->update(['ended_at' => $end, 'minutes' => $this->minutesBetween($break->started_at, $end), 'ended_by' => $user->id, 'note' => trim(($break->note ? $break->note.' · ' : '').'Ended automatically at clock-out')]);
            }
            $record->fill(['clock_out_at' => $at, 'clock_out_source' => $source]);
            $this->evaluate($record, $employee);
            $record->save();

            return $record;
        });
    }

    /** Recomputes status, lateness, worked time and overtime for one day. */
    public function evaluate(AttendanceRecord $record, Contact $employee): void
    {
        $tz = $this->timezone($employee);
        $date = $record->work_date instanceof \DateTimeInterface ? $record->work_date->format('Y-m-d') : (string) $record->work_date;
        $roster = RosterEntry::query()->with('workShift')->where('contact_id', $employee->id)->where('work_date', $date)->where('status', 'scheduled')->orderBy('id')->first();
        $record->roster_entry_id = $roster?->id;
        $storeId = $record->store_id;
        $grace = $roster?->workShift?->grace_minutes ?? (int) Settings::get('hr.grace_minutes', $storeId);
        $halfDay = (int) Settings::get('hr.half_day_minutes');
        $overtimeAfter = (int) Settings::get('hr.overtime_after_minutes');

        $this->applyBreakTotals($record, $employee);
        $record->late_minutes = 0;
        $record->early_leave_minutes = 0;
        $record->overtime_minutes = 0;
        $record->worked_minutes = 0;
        if (! $record->clock_in_at) {
            return;
        }
        $in = CarbonImmutable::instance($record->clock_in_at)->setTimezone($tz);
        $status = 'present';
        if ($roster) {
            [$start, $end] = $this->window($date, $roster->workShift, $tz);
            $lateBy = (int) floor(($in->getTimestamp() - $start->getTimestamp()) / 60);
            if ($lateBy > $grace) {
                $record->late_minutes = $lateBy;
                $status = 'late';
            }
            if ($record->clock_out_at) {
                $out = CarbonImmutable::instance($record->clock_out_at)->setTimezone($tz);
                $record->early_leave_minutes = max(0, (int) floor(($end->getTimestamp() - $out->getTimestamp()) / 60));
                $record->overtime_minutes = max(0, (int) floor(($out->getTimestamp() - $end->getTimestamp()) / 60) - $overtimeAfter);
            }
        }
        if ($record->clock_out_at) {
            $out = CarbonImmutable::instance($record->clock_out_at)->setTimezone($tz);
            // Real breaks replace the shift's planned break only when unpaid breaks are being deducted and some were recorded.
            $break = ((bool) Settings::get('hr.deduct_unpaid_breaks') && (int) $record->break_minutes > 0)
                ? (int) $record->unpaid_break_minutes
                : ($roster?->workShift?->break_minutes ?? 0);
            $record->worked_minutes = max(0, (int) floor(($out->getTimestamp() - $in->getTimestamp()) / 60) - $break);
            if ($record->worked_minutes < $halfDay) {
                $status = 'half_day';
            }
        }
        $record->status = $status;
    }

    /** The employee (or a manager for them) starts a break. Only one break can be open at a time. */
    public function startBreak(User $user, Contact $employee, string $type, bool $unpaid = false): AttendanceBreak
    {
        $this->authorizePunch($user, $employee);
        $this->assertType($type);

        return DB::transaction(function () use ($user, $employee, $type, $unpaid) {
            $record = AttendanceRecord::query()->where('contact_id', $employee->id)->whereNotNull('clock_in_at')->whereNull('clock_out_at')
                ->orderByDesc('work_date')->lockForUpdate()->first();
            if ($record === null) {
                throw new BusinessRuleException("{$employee->name} is not clocked in, so there is no working day to take a break from.", 'not_clocked_in');
            }
            if (AttendanceBreak::query()->where('contact_id', $employee->id)->whereNull('ended_at')->exists()) {
                throw new BusinessRuleException("{$employee->name} is already on a break. End it first.", 'break_open');
            }
            $break = AttendanceBreak::create([
                'store_id' => $record->store_id, 'contact_id' => $employee->id, 'attendance_record_id' => $record->id, 'work_date' => $record->work_date->toDateString(),
                'type' => $type, 'started_at' => now()->utc(), 'is_unpaid' => $unpaid,
                'entered_by' => $user->id, 'entered_by_role' => $this->enteredByRole($user, $employee),
            ]);
            Audit::record('hr.break_started', "{$employee->name} started a {$type} break", $break);

            return $break;
        });
    }

    public function endBreak(User $user, AttendanceBreak $break): AttendanceBreak
    {
        $employee = $break->contact;
        $this->authorizePunch($user, $employee);
        if ($break->ended_at !== null) {
            throw new BusinessRuleException('This break has already ended.', 'break_closed');
        }

        return DB::transaction(function () use ($user, $break, $employee) {
            $end = CarbonImmutable::now()->utc();
            if ($end->lt($break->started_at)) {
                $end = CarbonImmutable::instance($break->started_at);
            }
            $break->update(['ended_at' => $end, 'minutes' => $this->minutesBetween($break->started_at, $end), 'ended_by' => $user->id]);
            $this->refreshDay($employee, $break->work_date->toDateString());
            Audit::record('hr.break_ended', "{$employee->name} ended a {$break->type} break ({$break->minutes} min)", $break);

            return $break;
        });
    }

    /**
     * A break typed in afterwards (a forgotten Start/End). Managers can do this for anyone; an employee only for
     * themselves and only for the last three days. Who entered it is always kept.
     */
    public function addBreak(User $user, Contact $employee, string $date, string $type, string $from, string $to, bool $unpaid, ?string $note): AttendanceBreak
    {
        $this->authorizePunch($user, $employee);
        $this->assertType($type);
        $self = $this->enteredByRole($user, $employee) === 'employee';
        if ($self && CarbonImmutable::parse($date)->lt(CarbonImmutable::today()->subDays(3))) {
            throw new BusinessRuleException('You can add your own breaks for the last 3 days. Ask a manager for older days.', 'break_too_old');
        }
        $tz = $this->timezone($employee);
        $start = CarbonImmutable::parse($date.' '.$from, $tz)->utc();
        $end = CarbonImmutable::parse($date.' '.$to, $tz)->utc();
        if ($end->lte($start)) {
            throw new BusinessRuleException('The break must end after it starts.', 'break_order', field: 'to');
        }

        return DB::transaction(function () use ($user, $employee, $date, $type, $start, $end, $unpaid, $note) {
            $record = AttendanceRecord::query()->where('contact_id', $employee->id)->where('work_date', $date)->lockForUpdate()->first();
            if ($record === null || $record->clock_in_at === null) {
                throw new BusinessRuleException("{$employee->name} has no clock-in on that day. Correct the attendance first.", 'no_attendance', field: 'date');
            }
            if ($start->lt($record->clock_in_at) || ($record->clock_out_at && $end->gt($record->clock_out_at))) {
                throw new BusinessRuleException('The break falls outside the hours clocked that day.', 'break_outside_day', field: 'from');
            }
            $overlap = AttendanceBreak::query()->where('contact_id', $employee->id)->where('work_date', $date)
                ->where('started_at', '<', $end)->where(fn ($q) => $q->whereNull('ended_at')->orWhere('ended_at', '>', $start))->exists();
            if ($overlap) {
                throw new BusinessRuleException('This overlaps another break that day.', 'break_overlap', field: 'from');
            }
            $break = AttendanceBreak::create([
                'store_id' => $record->store_id, 'contact_id' => $employee->id, 'attendance_record_id' => $record->id, 'work_date' => $date,
                'type' => $type, 'started_at' => $start, 'ended_at' => $end, 'minutes' => $this->minutesBetween($start, $end), 'is_unpaid' => $unpaid,
                'entered_by' => $user->id, 'entered_by_role' => $this->enteredByRole($user, $employee), 'ended_by' => $user->id, 'note' => $note,
            ]);
            $this->refreshDay($employee, $date);
            Audit::record('hr.break_added', "Break of {$break->minutes} min added for {$employee->name} on {$date}", $break);

            return $break;
        });
    }

    public function deleteBreak(User $user, AttendanceBreak $break): void
    {
        if (! $user->hasPermission('hr.attendance.manage')) {
            throw new BusinessRuleException('Only a manager can remove a break.', 'not_permitted');
        }
        $employee = $break->contact;
        DB::transaction(function () use ($break, $employee) {
            $date = $break->work_date->toDateString();
            Audit::record('hr.break_removed', "Break of {$break->minutes} min removed for {$employee->name} on {$date}", $break, $break->only(['type', 'started_at', 'ended_at', 'minutes', 'is_unpaid', 'entered_by', 'entered_by_role']));
            $break->delete();
            $this->refreshDay($employee, $date);
        });
    }

    /** Recomputes the day's totals after a break changed. */
    private function refreshDay(Contact $employee, string $date): void
    {
        $record = AttendanceRecord::query()->where('contact_id', $employee->id)->where('work_date', $date)->first();
        if ($record) {
            $this->evaluate($record, $employee);
            $record->save();
        }
    }

    /** Sums the day's finished breaks and flags time above the allowed minutes. */
    private function applyBreakTotals(AttendanceRecord $record, Contact $employee): void
    {
        $date = $record->work_date instanceof \DateTimeInterface ? $record->work_date->format('Y-m-d') : (string) $record->work_date;
        $breaks = AttendanceBreak::query()->where('contact_id', $employee->id)->where('work_date', $date)->whereNotNull('ended_at')->get(['minutes', 'is_unpaid']);
        $total = (int) $breaks->sum('minutes');
        $allowed = (int) Settings::get('hr.break_allowed_minutes', $record->store_id);
        $record->break_minutes = $total;
        $record->unpaid_break_minutes = (int) $breaks->where('is_unpaid', true)->sum('minutes');
        $record->break_over_minutes = $allowed > 0 ? max(0, $total - $allowed) : 0;
    }

    private function minutesBetween(\DateTimeInterface $from, \DateTimeInterface $to): int
    {
        return max(0, (int) floor((CarbonImmutable::instance($to)->getTimestamp() - CarbonImmutable::instance($from)->getTimestamp()) / 60));
    }

    private function enteredByRole(User $user, Contact $employee): string
    {
        return $user->employee?->id === $employee->id ? 'employee' : 'manager';
    }

    private function assertType(string $type): void
    {
        if (! in_array($type, AttendanceBreak::TYPES, true)) {
            throw new BusinessRuleException('Choose meal, prayer or other.', 'invalid_break_type', field: 'type');
        }
    }

    /**
     * Marks rostered employees with no punch, no leave and no holiday as
     * absent for a past day (run nightly by the scheduler).
     */
    public function markAbsences(string $date): int
    {
        $count = 0;
        foreach (RosterEntry::query()->with('contact.employeeDetail')->where('work_date', $date)->where('status', 'scheduled')->get() as $entry) {
            if (AttendanceRecord::query()->where('contact_id', $entry->contact_id)->where('work_date', $date)->exists()) {
                continue;
            }
            $onLeave = LeaveRequest::query()->where('contact_id', $entry->contact_id)->where('status', 'approved')->where('start_date', '<=', $date)->where('end_date', '>=', $date)->exists();
            $holiday = Holiday::query()->where('date', $date)->where(fn ($q) => $q->whereNull('store_id')->orWhere('store_id', $entry->store_id))->exists();
            AttendanceRecord::create(['contact_id' => $entry->contact_id, 'work_date' => $date, 'store_id' => $entry->store_id, 'roster_entry_id' => $entry->id, 'status' => $onLeave ? 'on_leave' : ($holiday ? 'holiday' : 'absent')]);
            $count++;
        }

        return $count;
    }

    public function requestCorrection(User $user, Contact $employee, string $date, ?string $clockIn, ?string $clockOut, string $reason): AttendanceCorrection
    {
        if ($user->employee?->id !== $employee->id && ! $user->hasPermission('hr.attendance.manage')) {
            throw new BusinessRuleException('You can only request corrections for yourself.', 'not_permitted');
        }
        $tz = $this->timezone($employee);
        $record = AttendanceRecord::query()->where('contact_id', $employee->id)->where('work_date', $date)->first();

        return AttendanceCorrection::create([
            'contact_id' => $employee->id, 'attendance_record_id' => $record?->id, 'work_date' => $date,
            'requested_clock_in' => $clockIn ? CarbonImmutable::parse($date.' '.$clockIn, $tz)->utc() : null,
            'requested_clock_out' => $clockOut ? CarbonImmutable::parse($date.' '.$clockOut, $tz)->utc() : null,
            'reason' => $reason, 'status' => 'pending', 'requested_by' => $user->id,
            'original_values' => $record?->only(['clock_in_at', 'clock_out_at', 'status']),
        ]);
    }

    public function reviewCorrection(User $user, AttendanceCorrection $correction, bool $approve, ?string $note): AttendanceCorrection
    {
        if (! $user->hasPermission('hr.attendance.approve')) {
            throw new BusinessRuleException('You are not allowed to approve corrections.', 'not_permitted');
        }
        if ($correction->requested_by === $user->id && ! $user->isOwner()) {
            throw new BusinessRuleException('Someone else must approve your own correction.', 'self_approval');
        }
        if ($correction->status !== 'pending') {
            throw new BusinessRuleException('This correction was already reviewed.', 'not_pending');
        }

        return DB::transaction(function () use ($user, $correction, $approve, $note) {
            $correction->update(['status' => $approve ? 'approved' : 'rejected', 'reviewed_by' => $user->id, 'reviewed_at' => now(), 'review_note' => $note]);
            if ($approve) {
                $employee = $correction->contact;
                $record = AttendanceRecord::query()->firstOrNew(['contact_id' => $employee->id, 'work_date' => $correction->work_date->toDateString()], ['store_id' => $employee->employeeDetail?->store_id]);
                $record->fill(array_filter(['clock_in_at' => $correction->requested_clock_in, 'clock_out_at' => $correction->requested_clock_out]) + ['clock_in_source' => $record->clock_in_source ?? 'manual', 'is_corrected' => true, 'note' => 'Corrected: '.$correction->reason]);
                $this->evaluate($record, $employee);
                $record->save();
                $correction->update(['attendance_record_id' => $record->id]);
                Audit::record('hr.attendance_corrected', "Attendance of {$employee->name} on {$correction->work_date->toDateString()} corrected", $record, $correction->original_values ?? [], $record->only(['clock_in_at', 'clock_out_at', 'status']));
            }

            return $correction;
        });
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function window(string $date, WorkShift $shift, string $tz = 'Asia/Karachi'): array
    {
        $start = CarbonImmutable::parse($date.' '.$shift->start_time, $tz);
        $end = CarbonImmutable::parse($date.' '.$shift->end_time, $tz);
        if ($end->lte($start)) {
            $end = $end->addDay(); // night shift past midnight
        }

        return [$start, $end];
    }

    private function timezone(Contact $employee): string
    {
        $storeId = $employee->employeeDetail?->store_id ?? $employee->store_id;
        $store = $storeId ? Store::query()->find($storeId) : null;

        return $store?->timezone() ?? 'Asia/Karachi';
    }

    private function authorizePunch(User $user, Contact $employee): void
    {
        if ($user->employee?->id === $employee->id) {
            return;
        }
        if (! $user->hasPermission('hr.attendance.manage')) {
            throw new BusinessRuleException('You can only clock in for yourself.', 'not_permitted');
        }
    }
}
