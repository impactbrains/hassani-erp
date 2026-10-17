<?php

namespace App\Http\Controllers\Hr;

use App\Domain\Hr\LeaveService;
use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LeaveController extends Controller
{
    public function index(Request $request): Response
    {
        $this->allow($request, 'hr.leave.view');

        return Inertia::render('hr/Leave', [
            'requests' => LeaveRequest::query()->with('contact:id,name', 'leaveType:id,name', 'reviewer:id,name')
                ->when($request->query('status', 'pending') !== 'all', fn ($q) => $q->where('status', $request->query('status', 'pending')))
                ->latest('start_date')->paginate(50)->withQueryString(),
            'types' => LeaveType::query()->where('is_active', true)->get(['id', 'name', 'name_ur', 'is_paid']),
            'holidays' => Holiday::query()->where('date', '>=', now()->startOfYear()->toDateString())->orderBy('date')->get(['id', 'date', 'name', 'name_ur', 'store_id']),
            'filters' => ['status' => $request->query('status', 'pending')],
            'canApprove' => $request->user()->hasPermission('hr.leave.approve'),
        ]);
    }

    public function store(Request $request, LeaveService $leave): RedirectResponse
    {
        $data = $request->validate(['contact_id' => ['nullable', 'integer'], 'leave_type_id' => ['required', 'integer'], 'start_date' => ['required', 'date'], 'end_date' => ['required', 'date', 'after_or_equal:start_date'], 'half_day' => ['boolean'], 'reason' => ['nullable', 'string', 'max:500']]);
        $employee = ! empty($data['contact_id']) ? Contact::query()->employees()->findOrFail($data['contact_id']) : ($request->user()->employee ?? abort(422, 'Your login is not linked to an employee record.'));
        $leave->request($request->user(), $employee, $data['leave_type_id'], $data['start_date'], $data['end_date'], (bool) ($data['half_day'] ?? false), $data['reason'] ?? null);

        return back()->with('success', 'Leave requested.');
    }

    public function review(Request $request, LeaveRequest $leaveRequest, LeaveService $leave): RedirectResponse
    {
        $data = $request->validate(['decision' => ['required', 'in:approve,reject,cancel'], 'note' => ['nullable', 'string', 'max:300']]);
        $data['decision'] === 'cancel' ? $leave->cancel($request->user(), $leaveRequest) : $leave->review($request->user(), $leaveRequest, $data['decision'] === 'approve', $data['note'] ?? null);

        return back()->with('success', 'Leave updated.');
    }

    public function holiday(Request $request): RedirectResponse
    {
        $this->allow($request, 'hr.policies.manage');
        $data = $request->validate(['date' => ['required', 'date'], 'name' => ['required', 'string', 'max:120'], 'name_ur' => ['nullable', 'string', 'max:120'], 'store_id' => ['nullable', 'integer'], 'is_paid' => ['boolean']]);
        Holiday::create($data + ['type' => 'public', 'is_paid' => $data['is_paid'] ?? true, 'created_by' => $request->user()->id]);

        return back()->with('success', 'Holiday added.');
    }
}
