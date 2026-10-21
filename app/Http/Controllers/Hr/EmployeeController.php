<?php

namespace App\Http\Controllers\Hr;

use App\Domain\Hr\EmployeeExportService;
use App\Domain\Hr\EmployeeService;
use App\Domain\Identity\UserAccountService;
use App\Domain\Hr\LeaveService;
use App\Http\Controllers\Controller;
use App\Models\AttendanceRecord;
use App\Models\Contact;
use App\Models\Department;
use App\Models\EmployeeAdvance;
use App\Models\EmployeeDocument;
use App\Models\EmployeeLifecycleEvent;
use App\Models\JobPosition;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Role;
use App\Models\Payslip;
use App\Models\SalaryStructure;
use App\Models\User;
use App\Support\Audit;
use App\Support\Pager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Employee records and their lifecycle (onboarding → probation → active →
 * suspended / resigned / terminated). Identity and bank details and
 * documents need "hr.employees.sensitive"; documents live on private disk
 * and open through short-lived signed links.
 */
class EmployeeController extends Controller
{
    private const SENSITIVE = ['cnic', 'cnic_hash', 'cnic_expiry', 'bank_name', 'bank_account', 'date_of_birth', 'father_name', 'private_address', 'eobi_no', 'social_security_no'];

    public function index(Request $request): Response
    {
        $this->allow($request, 'hr.employees.view');
        Pager::remember($request, 'hr.employees');
        $employees = $this->listQuery($request)->with(['employeeDetail' => fn ($d) => $d->select(['id', 'contact_id', 'employee_no', 'store_id', 'department_id', 'job_title', 'role_type', 'status', 'joining_date'])->with('store:id,name', 'department:id,name'), 'user:id,email,status'])
            ->paginate($this->perPage($request, 30))->withQueryString();

        return Inertia::render('hr/Employees', ['employees' => $employees, 'filters' => $request->only(['q', 'status', 'store_id', 'department_id']), 'departments' => Department::query()->orderBy('name')->get(['id', 'name'])]);
    }

    /** The employees list as filtered on screen. The next / previous arrows on an employee walk through this same list. */
    private function listQuery(Request $request): \Illuminate\Database\Eloquent\Builder
    {
        $q = trim((string) $request->query('q'));

        return Contact::query()->employees()
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('name', 'ilike', "%{$q}%")->orWhere('phone', 'like', "%{$q}%")->orWhereHas('employeeDetail', fn ($d) => $d->where('employee_no', 'ilike', "%{$q}%"))))
            ->when($request->query('status', 'current') === 'current', fn ($w) => $w->whereHas('employeeDetail', fn ($d) => $d->whereNotIn('status', ['resigned', 'terminated'])))
            ->when(! in_array($request->query('status', 'current'), ['current', 'all'], true), fn ($w) => $w->whereHas('employeeDetail', fn ($d) => $d->where('status', $request->query('status'))))
            ->when($request->query('store_id'), fn ($w, $s) => $w->whereHas('employeeDetail', fn ($d) => $d->where('store_id', $s)))
            ->when($request->query('department_id'), fn ($w, $s) => $w->whereHas('employeeDetail', fn ($d) => $d->where('department_id', $s)))
            ->orderBy('name')->orderBy('contacts.id');
    }

    public function create(Request $request): Response
    {
        $this->allow($request, 'hr.employees.manage');

        return Inertia::render('hr/EmployeeForm', ['employee' => null, 'showSensitive' => $request->user()->hasPermission('hr.employees.sensitive')] + $this->formData($request));
    }

    public function store(Request $request, EmployeeService $employees, UserAccountService $accounts): RedirectResponse
    {
        $data = $this->validated($request);
        [$employee, $secret] = DB::transaction(function () use ($request, $employees, $accounts, $data) {
            $employee = $employees->save($request->user(), $data);

            return [$employee, $this->createLoginIfAsked($request, $accounts, $employee, $data)];
        });

        return redirect()->route('hr.employees.show', $employee)->with('success', "{$employee->name} added.")->with('secret', $secret);
    }

    public function show(Request $request, Contact $employee): Response
    {
        $this->allow($request, 'hr.employees.view');
        abort_unless($employee->type === 'employee', 404);
        $user = $request->user();
        $sensitive = $user->hasPermission('hr.employees.sensitive');
        $employee->load(['employeeDetail.store:id,name', 'employeeDetail.department:id,name', 'employeeDetail.position:id,name', 'employeeDetail.manager:id,name', 'user:id,email,status,last_login_at']);
        if (! $sensitive && $employee->employeeDetail) {
            $employee->employeeDetail->makeHidden(self::SENSITIVE);
        }
        $employee->employeeDetail?->makeHidden(['cnic_hash']);
        $lifecycle = $user->hasPermission('hr.employees.lifecycle');
        if (! $lifecycle) {
            $employee->employeeDetail?->makeHidden(['employer_insights', 'insights_by', 'insights_at']);
        }
        $canPayroll = $user->hasPermission('hr.payroll.view');
        $year = now()->year;

        return Inertia::render('hr/EmployeeShow', [
            'pager' => Pager::for($request, 'hr.employees', $employee->id, fn (Request $r) => $this->listQuery($r), fn (int $id) => route('hr.employees.show', $id)),
            'employee' => $employee,
            'events' => EmployeeLifecycleEvent::query()->where('contact_id', $employee->id)->with('recorder:id,name')->latest('effective_date')->get()->map(function ($event) use ($lifecycle) {
                $details = $event->details;
                if (! $lifecycle && isset($details['previous_period']['employer_insights'])) {
                    unset($details['previous_period']['employer_insights']);
                    $event->details = $details;
                }

                return $event;
            }),
            'documents' => $sensitive ? EmployeeDocument::query()->where('contact_id', $employee->id)->with('uploader:id,name')->latest()->get(['id', 'contact_id', 'type', 'title', 'expires_on', 'uploaded_by', 'created_at']) : null,
            'attendance' => AttendanceRecord::query()->where('contact_id', $employee->id)->where('work_date', '>=', now()->subDays(31)->toDateString())->orderByDesc('work_date')->get(),
            'leave' => LeaveRequest::query()->where('contact_id', $employee->id)->with('leaveType:id,name')->latest('start_date')->limit(20)->get(),
            'leaveBalances' => LeaveType::query()->where('is_active', true)->get()->map(fn ($t) => ['type' => $t->name] + app(LeaveService::class)->balance($employee, $t, $year)),
            'payslips' => $canPayroll ? Payslip::query()->where('contact_id', $employee->id)->with('run:id,number,period_start,period_end,status')->latest('id')->limit(12)->get(['id', 'payroll_run_id', 'gross_pay', 'total_deductions', 'net_pay', 'status', 'paid_at']) : null,
            'salary' => $canPayroll ? SalaryStructure::query()->where('contact_id', $employee->id)->latest('effective_from')->get() : null,
            'advances' => $canPayroll ? EmployeeAdvance::query()->where('contact_id', $employee->id)->latest('id')->get() : null,
            'logins' => $user->hasPermission('settings.users.manage') ? User::query()->inCurrentCompany()->whereDoesntHave('employee')->orderBy('name')->get(['id', 'name', 'email']) : null,
            'can' => ['manage' => $user->hasPermission('hr.employees.manage'), 'lifecycle' => $lifecycle, 'export' => $lifecycle && $user->hasPermission('hr.attendance.view'), 'sensitive' => $sensitive, 'payroll' => $user->hasPermission('hr.payroll.manage'), 'users' => $user->hasPermission('settings.users.manage')],
        ]);
    }

    public function edit(Request $request, Contact $employee): Response
    {
        $this->allow($request, 'hr.employees.manage');
        $employee->load('employeeDetail', 'user:id,email');
        $employee->employeeDetail?->makeHidden(['employer_insights', 'insights_by', 'insights_at']);
        $sensitive = $request->user()->hasPermission('hr.employees.sensitive');
        if (! $sensitive) {
            $employee->employeeDetail?->makeHidden(self::SENSITIVE);
        }

        return Inertia::render('hr/EmployeeForm', ['employee' => $employee, 'showSensitive' => $sensitive, 'hasLogin' => $employee->user?->email] + $this->formData($request, $employee));
    }

    public function update(Request $request, Contact $employee, EmployeeService $employees, UserAccountService $accounts): RedirectResponse
    {
        $data = $this->validated($request);
        $secret = DB::transaction(function () use ($request, $employees, $accounts, $employee, $data) {
            $employees->save($request->user(), $data, $employee);

            return $this->createLoginIfAsked($request, $accounts, $employee->fresh(['employeeDetail', 'user']), $data);
        });

        return redirect()->route('hr.employees.show', $employee)->with('success', 'Saved.')->with('secret', $secret);
    }

    public function transition(Request $request, Contact $employee, EmployeeService $employees): RedirectResponse
    {
        $data = $request->validate(['action' => ['required', 'in:confirm,start_probation,suspend,reinstate,resign,terminate,transfer,rehire'], 'effective_date' => ['required', 'date'], 'note' => ['nullable', 'string', 'max:500'], 'insights' => ['nullable', 'string', 'max:5000'], 'store_id' => ['nullable', 'integer'], 'department_id' => ['nullable', 'integer'], 'job_position_id' => ['nullable', 'integer']]);
        $data['action'] === 'transfer'
            ? $employees->transfer($request->user(), $employee, $data['store_id'] ?? null, $data['department_id'] ?? null, $data['job_position_id'] ?? null, $data['effective_date'], $data['note'] ?? null)
            : $employees->transition($request->user(), $employee, $data['action'], $data['effective_date'], $data['note'] ?? null, $data['insights'] ?? null);

        return back()->with('success', 'Employment record updated.');
    }

    /** The leaving record: ?format=xlsx downloads an Excel file, ?format=print opens a page to print or save as PDF. */
    public function export(Request $request, Contact $employee, EmployeeExportService $exports): \Symfony\Component\HttpFoundation\Response
    {
        $this->allow($request, 'hr.employees.lifecycle');
        abort_unless($employee->type === 'employee', 404);
        $data = $exports->build($request->user(), $employee);
        $name = 'employee-record-'.\Illuminate\Support\Str::slug($employee->name ?: 'employee').'-'.$data['period']['to'];
        if ($request->query('format') === 'print') {
            return response()->view('hr.employee-export', ['data' => $data])->header('Cache-Control', 'no-store');
        }
        $path = $exports->toXlsx($data);

        return response()->download($path, $name.'.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'Cache-Control' => 'no-store'])->deleteFileAfterSend(true);
    }

    public function insights(Request $request, Contact $employee, EmployeeService $employees): RedirectResponse
    {
        $data = $request->validate(['insights' => ['nullable', 'string', 'max:5000']]);
        $employees->saveInsights($request->user(), $employee, $data['insights'] ?? null);

        return back()->with('success', 'Employer insights saved.');
    }

    public function salary(Request $request, Contact $employee): RedirectResponse
    {
        $this->allow($request, 'hr.payroll.manage');
        $data = $request->validate(['effective_from' => ['required', 'date'], 'pay_basis' => ['required', 'in:monthly,daily,hourly'], 'base_amount' => ['required', 'numeric', 'min:0'], 'overtime_hourly_rate' => ['nullable', 'numeric', 'min:0'],
            'components' => ['array'], 'components.*.code' => ['required', 'string', 'max:20'], 'components.*.name' => ['required', 'string', 'max:80'], 'components.*.type' => ['required', 'in:allowance,deduction'], 'components.*.amount' => ['required', 'numeric', 'min:0'],
            'eobi_employee' => ['nullable', 'numeric', 'min:0'], 'eobi_employer' => ['nullable', 'numeric', 'min:0'], 'social_security_employer' => ['nullable', 'numeric', 'min:0']]);
        foreach (['eobi_employee', 'eobi_employer', 'social_security_employer'] as $field) {
            $data[$field] = $data[$field] ?? 0;
        }
        SalaryStructure::query()->where('contact_id', $employee->id)->whereNull('effective_to')->where('effective_from', '<', $data['effective_from'])
            ->update(['effective_to' => \Carbon\CarbonImmutable::parse($data['effective_from'])->subDay()->toDateString()]);
        $structure = SalaryStructure::create($data + ['contact_id' => $employee->id, 'components' => $data['components'] ?? [], 'created_by' => $request->user()->id, 'approved_by' => $request->user()->hasPermission('hr.payroll.approve') ? $request->user()->id : null]);
        Audit::record('hr.salary_changed', "Salary of {$employee->name} set from {$data['effective_from']}", $structure, new: ['base_amount' => $data['base_amount']]);

        return back()->with('success', 'Salary saved.');
    }

    public function linkLogin(Request $request, Contact $employee, \App\Domain\Identity\UserAccountService $accounts): RedirectResponse
    {
        $user = User::query()->inCurrentCompany()->findOrFail($request->validate(['user_id' => ['required', 'integer']])['user_id']);
        $accounts->linkEmployee($request->user(), $user, $employee->id);

        return back()->with('success', 'Login linked.');
    }

    public function uploadDocument(Request $request, Contact $employee, EmployeeService $employees): RedirectResponse
    {
        $data = $request->validate(['file' => ['required', 'file', 'max:8192'], 'type' => ['required', 'in:cnic,contract,cv,certificate,police_verification,medical,photo,other'], 'title' => ['required', 'string', 'max:120'], 'expires_on' => ['nullable', 'date']]);
        $employees->uploadDocument($request->user(), $employee, $request->file('file'), $data['type'], $data['title'], $data['expires_on'] ?? null);

        return back()->with('success', 'Document uploaded to private storage.');
    }

    public function documentLink(Request $request, EmployeeDocument $document, EmployeeService $employees): \Illuminate\Http\JsonResponse
    {
        return response()->json(['url' => $employees->documentLink($request->user(), $document)]);
    }

    /** Signed, five-minute link; also re-checks the viewer still has access. */
    public function download(Request $request, EmployeeDocument $document, EmployeeService $employees): StreamedResponse
    {
        abort_unless($request->hasValidSignature() && (int) $request->query('u') === $request->user()?->id && $request->user()->hasPermission('hr.employees.sensitive'), 403);

        return $employees->documentResponse($document);
    }

    /** @return array<string, mixed> */
    private function formData(Request $request, ?Contact $employee = null): array
    {
        $user = $request->user();
        $canLogin = $user->hasPermission('settings.users.manage') && ! $employee?->user;
        $accounts = app(UserAccountService::class);

        return [
            'departments' => Department::query()->orderBy('name')->get(['id', 'name']),
            'positions' => JobPosition::query()->orderBy('name')->get(['id', 'name', 'department_id']),
            'managers' => Contact::query()->employees()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'canCreateLogin' => $canLogin,
            // Only roles this person is allowed to hand out; the server checks again when saving.
            'loginRoles' => $canLogin ? Role::query()->with('permissions')->orderBy('name')->get()->filter(fn (Role $r) => $accounts->canGrant($user, $r))->map(fn (Role $r) => ['id' => $r->id, 'name' => $r->name, 'description' => $r->description])->values() : [],
            'minPasswordLength' => (int) \App\Support\Settings\Settings::get('security.password_min_length'),
        ];
    }

    /**
     * "Also give this employee a login": creates the account, links it, gives the chosen role for the employee's
     * store and returns the temporary password to show once (null when no login was asked for).
     *
     * @param  array<string, mixed>  $data
     * @return array{label: string, value: string, note: string}|null
     */
    private function createLoginIfAsked(Request $request, UserAccountService $accounts, Contact $employee, array $data): ?array
    {
        if (! $request->boolean('create_login') || $employee->user) {
            return null;
        }
        $request->validate([
            'login_role_id' => ['required', 'integer'],
            'login_password' => ['nullable', 'string', 'max:100', Password::min(max(8, (int) \App\Support\Settings\Settings::get('security.password_min_length')))->letters()->numbers()],
        ], [], ['login_role_id' => 'role', 'login_password' => 'temporary password']);
        if (empty($data['email'])) {
            throw \Illuminate\Validation\ValidationException::withMessages(['email' => 'Enter an e-mail address: it is what the employee signs in with.']);
        }
        $storeId = $data['store_id'] ?? $employee->employeeDetail?->store_id;
        if (! $storeId) {
            throw \Illuminate\Validation\ValidationException::withMessages(['store_id' => 'Choose the employee\'s store first: their role applies to that store.']);
        }
        $result = $accounts->create(
            $request->user(),
            ['name' => $employee->name, 'email' => $data['email'], 'employee_id' => $employee->id, 'temporary_password' => $request->input('login_password') ?: null],
            [['role_id' => (int) $request->input('login_role_id'), 'store_id' => (int) $storeId]],
        );

        return ['label' => 'Temporary password for '.$result['user']->email, 'value' => $result['temporary_password'], 'note' => 'Give this to the employee directly. It is shown only once and must be changed at first sign-in.'];
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'], 'name_ur' => ['nullable', 'string', 'max:120'], 'phone' => ['nullable', 'string', 'max:30'], 'email' => ['nullable', 'email'], 'address' => ['nullable', 'string', 'max:500'], 'city' => ['nullable', 'string', 'max:80'],
            'store_id' => ['nullable', 'integer'], 'department_id' => ['nullable', 'integer'], 'job_position_id' => ['nullable', 'integer'], 'job_title' => ['nullable', 'string', 'max:120'],
            'role_type' => ['nullable', 'in:cashier,sales_agent,rider,warehouse,supervisor,manager,admin,other'], 'manager_contact_id' => ['nullable', 'integer'], 'employment_type' => ['required', 'in:permanent,probation,contract,daily_wage,part_time'],
            'joining_date' => ['required', 'date'], 'probation_end_date' => ['nullable', 'date', 'after_or_equal:joining_date'], 'gender' => ['nullable', 'in:male,female,other'], 'marital_status' => ['nullable', 'in:single,married,widowed,divorced'],
            'emergency_contact_name' => ['nullable', 'string', 'max:120'], 'emergency_contact_phone' => ['nullable', 'string', 'max:30'], 'emergency_contact_relation' => ['nullable', 'string', 'max:60'], 'education_level' => ['nullable', 'string', 'max:80'], 'note' => ['nullable', 'string', 'max:2000'],
            'cnic' => ['nullable', 'string', 'max:20'], 'cnic_expiry' => ['nullable', 'date'], 'bank_name' => ['nullable', 'string', 'max:80'], 'bank_account' => ['nullable', 'string', 'max:40'], 'date_of_birth' => ['nullable', 'date', 'before:today'],
            'father_name' => ['nullable', 'string', 'max:120'], 'create_login' => ['boolean'], 'private_address' => ['nullable', 'string', 'max:500'], 'eobi_no' => ['nullable', 'string', 'max:40'], 'social_security_no' => ['nullable', 'string', 'max:40'],
        ]);

        // Sensitive fields are only changed when the user may, and only those the form sent.
        $sensitive = array_flip(self::SENSITIVE);
        if (! $request->user()->hasPermission('hr.employees.sensitive')) {
            return array_diff_key($data, $sensitive);
        }

        return array_diff_key($data, array_diff_key($sensitive, $request->all()));
    }
}
