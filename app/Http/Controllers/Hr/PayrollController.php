<?php

namespace App\Http\Controllers\Hr;

use App\Domain\Hr\AdvanceService;
use App\Domain\Hr\PayrollService;
use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\EmployeeAdvance;
use App\Models\PayrollAdjustment;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\Shift;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Monthly payroll (prepare → approve → post → pay), payslips, Peshgi and bonus/recovery adjustments. */
class PayrollController extends Controller
{
    public function index(Request $request): Response
    {
        $this->allow($request, 'hr.payroll.view');

        return Inertia::render('hr/Payroll', [
            'runs' => PayrollRun::query()->with('store:id,name', 'creator:id,name', 'approver:id,name')->withCount('payslips')->latest('period_start')->paginate(24),
            'adjustments' => PayrollAdjustment::query()->with('contact:id,name', 'requester:id,name')->whereIn('status', ['pending', 'approved'])->latest()->limit(100)->get(),
            'can' => ['manage' => $request->user()->hasPermission('hr.payroll.manage'), 'approve' => $request->user()->hasPermission('hr.payroll.approve')],
        ]);
    }

    public function prepare(Request $request, PayrollService $payroll): RedirectResponse
    {
        $data = $request->validate(['month' => ['required', 'date_format:Y-m'], 'store_id' => ['nullable', 'integer']]);
        $run = $payroll->prepare($request->user(), $data['month'].'-01', $data['store_id'] ?? null);

        return redirect()->route('hr.payroll.show', $run)->with('success', "Payroll {$run->number} prepared. Check each payslip before approving.");
    }

    public function show(Request $request, PayrollRun $run): Response
    {
        $this->allow($request, 'hr.payroll.view');

        return Inertia::render('hr/PayrollShow', [
            'run' => $run->load('store:id,name', 'creator:id,name', 'approver:id,name'),
            'payslips' => Payslip::query()->where('payroll_run_id', $run->id)->with('contact:id,name', 'lines', 'paymentMethod:id,name')->orderBy('id')->get(),
            'can' => ['approve' => $request->user()->hasPermission('hr.payroll.approve')],
        ]);
    }

    public function action(Request $request, PayrollRun $run, PayrollService $payroll): RedirectResponse
    {
        $data = $request->validate(['action' => ['required', 'in:approve,post,pay'], 'payment_method_id' => ['nullable', 'required_if:action,pay', 'integer'], 'reference' => ['nullable', 'string', 'max:80'], 'payslip_ids' => ['nullable', 'array']]);
        match ($data['action']) {
            'approve' => $payroll->approve($request->user(), $run),
            'post' => $payroll->post($request->user(), $run),
            'pay' => $payroll->markPaid($request->user(), $run, $data['payment_method_id'], $data['reference'] ?? null, $data['payslip_ids'] ?? null),
        };

        return back()->with('success', match ($data['action']) { 'approve' => 'Payroll approved.', 'post' => 'Payroll posted to the accounts.', default => 'Salaries marked as paid.' });
    }

    public function payslip(Request $request, Payslip $payslip): Response
    {
        $own = $request->user()->employee?->id === $payslip->contact_id;
        abort_unless($own || $request->user()->hasPermission('hr.payroll.view'), 403);

        return Inertia::render('hr/PayslipPrint', ['payslip' => $payslip->load('contact.employeeDetail:id,contact_id,employee_no,job_title,department_id', 'contact.employeeDetail.department:id,name', 'lines', 'run:id,number,period_start,period_end,status')]);
    }

    public function advances(Request $request): Response
    {
        $this->allow($request, 'hr.payroll.view');

        return Inertia::render('hr/Advances', [
            'advances' => EmployeeAdvance::query()->with('contact:id,name', 'requester:id,name', 'approver:id,name')->latest('id')->paginate(50),
            'openShift' => Shift::query()->where('status', 'open')->where('opened_by', $request->user()->id)->value('id'),
            'can' => ['manage' => $request->user()->hasPermission('hr.advances.manage'), 'approve' => $request->user()->hasPermission('hr.advances.approve')],
        ]);
    }

    public function requestAdvance(Request $request, AdvanceService $advances): RedirectResponse
    {
        $data = $request->validate(['contact_id' => ['nullable', 'integer'], 'amount' => ['required', 'numeric', 'gt:0'], 'installments' => ['required', 'integer', 'min:1'], 'recovery_start_month' => ['required', 'date_format:Y-m'], 'reason' => ['nullable', 'string', 'max:300']]);
        $employee = ! empty($data['contact_id']) ? Contact::query()->employees()->findOrFail($data['contact_id']) : ($request->user()->employee ?? abort(422, 'Your login is not linked to an employee record.'));
        $advances->request($request->user(), $employee, (string) $data['amount'], $data['installments'], $data['recovery_start_month'].'-01', $data['reason'] ?? null);

        return back()->with('success', 'Peshgi requested.');
    }

    public function advanceAction(Request $request, EmployeeAdvance $advance, AdvanceService $advances): RedirectResponse
    {
        $data = $request->validate(['action' => ['required', 'in:approve,reject,disburse,repay'], 'note' => ['nullable', 'string', 'max:300'], 'payment_method_id' => ['nullable', 'required_if:action,disburse,repay', 'integer'], 'shift_id' => ['nullable', 'integer'], 'amount' => ['nullable', 'required_if:action,repay', 'numeric', 'gt:0']]);
        match ($data['action']) {
            'approve', 'reject' => $advances->review($request->user(), $advance, $data['action'] === 'approve', $data['note'] ?? null),
            'disburse' => $advances->disburse($request->user(), $advance, $data['payment_method_id'], $data['shift_id'] ?? null),
            'repay' => $advances->repayInCash($request->user(), $advance, (string) $data['amount'], $data['payment_method_id']),
        };

        return back()->with('success', 'Peshgi updated.');
    }

    public function adjustment(Request $request, AdvanceService $advances): RedirectResponse
    {
        $data = $request->validate(['contact_id' => ['required', 'integer'], 'type' => ['required', 'in:bonus,allowance,reimbursement,deduction'], 'category' => ['required', 'in:bonus,incentive,commission,arrears,shortage_recovery,damage_recovery,other'], 'amount' => ['required', 'numeric', 'gt:0'], 'payroll_month' => ['required', 'date_format:Y-m'], 'reason' => ['required', 'string', 'max:300'], 'investigation_reference' => ['nullable', 'string', 'max:60']]);
        $advances->requestAdjustment($request->user(), ['payroll_month' => $data['payroll_month'].'-01'] + $data);

        return back()->with('success', 'Adjustment added for approval.');
    }

    public function reviewAdjustment(Request $request, PayrollAdjustment $adjustment, AdvanceService $advances): RedirectResponse
    {
        $advances->reviewAdjustment($request->user(), $adjustment, $request->validate(['approve' => ['required', 'boolean']])['approve']);

        return back();
    }
}
