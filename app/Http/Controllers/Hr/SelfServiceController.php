<?php

namespace App\Http\Controllers\Hr;

use App\Domain\Hr\LeaveService;
use App\Http\Controllers\Controller;
use App\Models\AttendanceBreak;
use App\Models\AttendanceRecord;
use App\Models\EmployeeAdvance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Payslip;
use App\Models\RosterEntry;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** "My work": the signed-in employee's own attendance, shifts, leave, payslips and Peshgi. */
class SelfServiceController extends Controller
{
    public function show(Request $request, LeaveService $leave): Response
    {
        $me = $request->user()->employee;
        if (! $me) {
            return Inertia::render('hr/Me', ['employee' => null]);
        }
        $today = $this->currentStore($request)->businessDate();

        return Inertia::render('hr/Me', [
            'employee' => $me->only(['id', 'name']),
            'today' => AttendanceRecord::query()->where('contact_id', $me->id)->where('work_date', $today)->first(),
            'breaksToday' => AttendanceBreak::query()->where('contact_id', $me->id)->where('work_date', $today)->orderBy('started_at')->get(['id', 'type', 'started_at', 'ended_at', 'minutes', 'is_unpaid', 'entered_by_role']),
            'attendance' => AttendanceRecord::query()->where('contact_id', $me->id)->where('work_date', '>=', now()->subDays(31)->toDateString())->orderByDesc('work_date')->get(),
            'shifts' => RosterEntry::query()->where('contact_id', $me->id)->where('work_date', '>=', $today)->where('status', 'scheduled')->with('workShift:id,name,start_time,end_time')->orderBy('work_date')->limit(14)->get(),
            'leave' => LeaveRequest::query()->where('contact_id', $me->id)->with('leaveType:id,name')->latest('start_date')->limit(20)->get(),
            'leaveTypes' => LeaveType::query()->where('is_active', true)->get(['id', 'name', 'name_ur'])->map(fn ($t) => $t->toArray() + $leave->balance($me, $t, now()->year)),
            'payslips' => Payslip::query()->where('contact_id', $me->id)->whereIn('status', ['final', 'paid'])->with('run:id,period_start,period_end')->latest('id')->limit(12)->get(['id', 'payroll_run_id', 'net_pay', 'status', 'paid_at']),
            'advances' => EmployeeAdvance::query()->where('contact_id', $me->id)->latest('id')->limit(10)->get(),
        ]);
    }
}
