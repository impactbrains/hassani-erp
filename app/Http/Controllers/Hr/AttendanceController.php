<?php

namespace App\Http\Controllers\Hr;

use App\Domain\Hr\AttendanceService;
use App\Http\Controllers\Controller;
use App\Models\AttendanceBreak;
use App\Models\AttendanceCorrection;
use App\Models\AttendanceRecord;
use App\Models\Contact;
use App\Models\RosterEntry;
use App\Models\WorkShift;
use App\Support\Settings\Settings;
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
            'breaks' => AttendanceBreak::query()->where('work_date', $date)->whereIn('contact_id', $employees->pluck('id'))->orderBy('started_at')->get(['id', 'contact_id', 'type', 'started_at', 'ended_at', 'minutes', 'is_unpaid'])->groupBy('contact_id'),
            'breakAllowed' => (int) Settings::get('hr.break_allowed_minutes', $store->id),
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

    /** Breaks tab: every break in a date range, who entered it, and days above the allowed minutes. */
    public function breaks(Request $request): Response
    {
        $store = $this->currentStore($request);
        $this->allow($request, 'hr.attendance.view');
        $to = $request->query('to', $store->businessDate());
        $from = $request->query('from', CarbonImmutable::parse($to)->subDays(6)->toDateString());
        $type = $request->query('type');
        $contactId = $request->integer('contact_id') ?: null;
        $allowed = (int) Settings::get('hr.break_allowed_minutes', $store->id);
        $employeeIds = Contact::query()->employees()->whereHas('employeeDetail', fn ($d) => $d->where('store_id', $store->id))->pluck('id');

        $query = AttendanceBreak::query()->whereIn('contact_id', $employeeIds)->whereBetween('work_date', [$from, $to])
            ->when(in_array($type, AttendanceBreak::TYPES, true), fn ($q) => $q->where('type', $type))
            ->when($contactId, fn ($q) => $q->where('contact_id', $contactId));
        $dayTotals = AttendanceRecord::query()->whereIn('contact_id', $employeeIds)->whereBetween('work_date', [$from, $to])->where('break_minutes', '>', 0)
            ->get(['contact_id', 'work_date', 'break_minutes', 'unpaid_break_minutes', 'break_over_minutes'])
            ->mapWithKeys(fn ($r) => [$r->contact_id.'|'.$r->work_date->toDateString() => ['total' => $r->break_minutes, 'unpaid' => $r->unpaid_break_minutes, 'over' => $r->break_over_minutes]]);
        if ($request->boolean('over_only')) {
            $overKeys = $dayTotals->filter(fn ($d) => $d['over'] > 0)->keys();
            $query->where(fn ($q) => $overKeys->isEmpty() ? $q->whereRaw('1 = 0') : $q->where(function ($w) use ($overKeys) {
                foreach ($overKeys as $key) {
                    [$cid, $day] = explode('|', $key);
                    $w->orWhere(fn ($x) => $x->where('contact_id', $cid)->where('work_date', $day));
                }
            }));
        }

        return Inertia::render('hr/Breaks', [
            'filters' => ['from' => $from, 'to' => $to, 'type' => $type, 'contact_id' => $contactId, 'over_only' => $request->boolean('over_only')],
            'breaks' => $query->with(['contact:id,name,name_ur', 'enteredBy:id,name', 'endedBy:id,name'])->orderByDesc('work_date')->orderByDesc('started_at')->paginate($this->perPage($request, 50))->withQueryString(),
            'dayTotals' => $dayTotals,
            'allowed' => $allowed,
            'employees' => Contact::query()->employees()->whereIn('id', $employeeIds)->orderBy('name')->get(['id', 'name']),
            'canManage' => $request->user()->hasPermission('hr.attendance.manage'),
            'today' => $store->businessDate(),
        ]);
    }

    public function startBreak(Request $request, AttendanceService $attendance): RedirectResponse
    {
        $data = $request->validate(['contact_id' => ['nullable', 'integer'], 'type' => ['required', 'in:meal,prayer,other'], 'unpaid' => ['boolean']]);
        $employee = ! empty($data['contact_id']) ? Contact::query()->employees()->findOrFail($data['contact_id']) : ($request->user()->employee ?? abort(422, 'Your login is not linked to an employee record.'));
        $attendance->startBreak($request->user(), $employee, $data['type'], (bool) ($data['unpaid'] ?? false));

        return back()->with('success', "{$employee->name}: {$data['type']} break started.");
    }

    public function endBreak(Request $request, AttendanceBreak $break, AttendanceService $attendance): RedirectResponse
    {
        $done = $attendance->endBreak($request->user(), $break);

        return back()->with('success', "Break ended ({$done->minutes} min).");
    }

    public function addBreak(Request $request, AttendanceService $attendance): RedirectResponse
    {
        $data = $request->validate(['contact_id' => ['nullable', 'integer'], 'date' => ['required', 'date', 'before_or_equal:today'], 'type' => ['required', 'in:meal,prayer,other'],
            'from' => ['required', 'date_format:H:i'], 'to' => ['required', 'date_format:H:i'], 'unpaid' => ['boolean'], 'note' => ['nullable', 'string', 'max:200']]);
        $employee = ! empty($data['contact_id']) ? Contact::query()->employees()->findOrFail($data['contact_id']) : ($request->user()->employee ?? abort(422, 'Your login is not linked to an employee record.'));
        $break = $attendance->addBreak($request->user(), $employee, $data['date'], $data['type'], $data['from'], $data['to'], (bool) ($data['unpaid'] ?? false), $data['note'] ?? null);

        return back()->with('success', "Break of {$break->minutes} min added.");
    }

    public function deleteBreak(Request $request, AttendanceBreak $break, AttendanceService $attendance): RedirectResponse
    {
        $attendance->deleteBreak($request->user(), $break);

        return back()->with('success', 'Break removed.');
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
