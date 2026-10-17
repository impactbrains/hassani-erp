<?php

namespace App\Http\Controllers\Hr;

use App\Domain\Hr\AttendanceService;
use App\Http\Controllers\Controller;
use App\Models\AttendanceCorrection;
use App\Models\AttendanceRecord;
use App\Models\Contact;
use App\Models\RosterEntry;
use App\Models\WorkShift;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Daily attendance board, roster (shift planning) and attendance corrections. */
class AttendanceController extends Controller
{
    public function index(Request $request): Response
    {
        $store = $this->currentStore($request);
        $this->allow($request, 'hr.attendance.view');
        $date = $request->query('date', $store->businessDate());
        $employees = Contact::query()->employees()->where('is_active', true)->whereHas('employeeDetail', fn ($d) => $d->where('store_id', $store->id)->whereNotIn('status', ['resigned', 'terminated']))->orderBy('name')->get(['id', 'name', 'name_ur']);

        return Inertia::render('hr/Attendance', [
            'date' => $date,
            'employees' => $employees,
            'records' => AttendanceRecord::query()->where('work_date', $date)->whereIn('contact_id', $employees->pluck('id'))->get()->keyBy('contact_id'),
            'roster' => RosterEntry::query()->where('work_date', $date)->whereIn('contact_id', $employees->pluck('id'))->with('workShift:id,name,start_time,end_time')->get()->groupBy('contact_id'),
            'corrections' => AttendanceCorrection::query()->where('status', 'pending')->with('contact:id,name', 'requester:id,name')->latest()->limit(50)->get(),
            'can' => ['manage' => $request->user()->hasPermission('hr.attendance.manage'), 'approve' => $request->user()->hasPermission('hr.attendance.approve')],
        ]);
    }

    public function punch(Request $request, AttendanceService $attendance): RedirectResponse
    {
        $data = $request->validate(['contact_id' => ['nullable', 'integer'], 'direction' => ['required', 'in:in,out']]);
        $employee = $data['contact_id'] ? Contact::query()->employees()->findOrFail($data['contact_id']) : ($request->user()->employee ?? abort(422, 'Your login is not linked to an employee record.'));
        $record = $data['direction'] === 'in'
            ? $attendance->clockIn($request->user(), $employee, null, 'web', substr((string) $request->userAgent(), 0, 60))
            : $attendance->clockOut($request->user(), $employee, null, 'web');

        return back()->with('success', "{$employee->name}: ".($data['direction'] === 'in' ? 'clocked in' : 'clocked out').($record->late_minutes > 0 ? " ({$record->late_minutes} min late)" : '').'.');
    }

    public function roster(Request $request): Response
    {
        $store = $this->currentStore($request);
        $this->allow($request, 'hr.attendance.view');
        $start = CarbonImmutable::parse($request->query('week', $store->businessDate()))->startOfWeek();
        $employees = Contact::query()->employees()->where('is_active', true)->whereHas('employeeDetail', fn ($d) => $d->where('store_id', $store->id)->whereNotIn('status', ['resigned', 'terminated']))->orderBy('name')->get(['id', 'name']);

        return Inertia::render('hr/Roster', [
            'week' => $start->toDateString(),
            'days' => collect(range(0, 6))->map(fn ($i) => $start->addDays($i)->toDateString()),
            'employees' => $employees,
            'shifts' => WorkShift::query()->where(fn ($q) => $q->whereNull('store_id')->orWhere('store_id', $store->id))->get(['id', 'name', 'start_time', 'end_time', 'color']),
            'entries' => RosterEntry::query()->whereBetween('work_date', [$start->toDateString(), $start->addDays(6)->toDateString()])->whereIn('contact_id', $employees->pluck('id'))->where('status', 'scheduled')->get(['id', 'contact_id', 'work_shift_id', 'work_date']),
            'canManage' => $request->user()->hasPermission('hr.attendance.manage'),
        ]);
    }

    public function assign(Request $request, AttendanceService $attendance): RedirectResponse
    {
        $this->allow($request, 'hr.attendance.manage');
        $data = $request->validate(['contact_id' => ['required', 'integer'], 'work_shift_id' => ['nullable', 'integer'], 'date' => ['required', 'date'], 'ignore_leave' => ['boolean']]);
        if (! $data['work_shift_id']) {
            RosterEntry::query()->where('contact_id', $data['contact_id'])->where('work_date', $data['date'])->where('status', 'scheduled')->update(['status' => 'cancelled']);

            return back();
        }
        $attendance->assignShift($request->user(), Contact::query()->employees()->findOrFail($data['contact_id']), $data['work_shift_id'], $data['date'], (bool) ($data['ignore_leave'] ?? false));

        return back();
    }

    public function requestCorrection(Request $request, AttendanceService $attendance): RedirectResponse
    {
        $data = $request->validate(['contact_id' => ['nullable', 'integer'], 'date' => ['required', 'date', 'before_or_equal:today'], 'clock_in' => ['nullable', 'date_format:H:i'], 'clock_out' => ['nullable', 'date_format:H:i'], 'reason' => ['required', 'string', 'min:5', 'max:500']]);
        $employee = ! empty($data['contact_id']) ? Contact::query()->employees()->findOrFail($data['contact_id']) : ($request->user()->employee ?? abort(422, 'Your login is not linked to an employee record.'));
        $attendance->requestCorrection($request->user(), $employee, $data['date'], $data['clock_in'] ?? null, $data['clock_out'] ?? null, $data['reason']);

        return back()->with('success', 'Correction requested.');
    }

    public function reviewCorrection(Request $request, AttendanceCorrection $correction, AttendanceService $attendance): RedirectResponse
    {
        $data = $request->validate(['approve' => ['required', 'boolean'], 'note' => ['nullable', 'string', 'max:300']]);
        $attendance->reviewCorrection($request->user(), $correction, $data['approve'], $data['note'] ?? null);

        return back()->with('success', 'Correction reviewed.');
    }
}
