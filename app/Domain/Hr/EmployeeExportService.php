<?php

namespace App\Domain\Hr;

use App\Exceptions\BusinessRuleException;
use App\Models\Approval;
use App\Models\AttendanceBreak;
use App\Models\AttendanceRecord;
use App\Models\CashMovement;
use App\Models\Contact;
use App\Models\CreditNote;
use App\Models\EmployeeAdvance;
use App\Models\EmployeeAsset;
use App\Models\EmployeeDocument;
use App\Models\EmployeeLifecycleEvent;
use App\Models\HrCase;
use App\Models\LeaveRequest;
use App\Models\Payslip;
use App\Models\SalaryStructure;
use App\Models\SalesInvoice;
use App\Models\Shift;
use App\Models\Store;
use App\Models\User;
use App\Support\Audit;
use App\Support\Xlsx\SimpleXlsx;
use Carbon\CarbonImmutable;

/**
 * Everything tied to one employee for their latest employment period (joining date to exit date, or to today
 * while still employed), as an Excel workbook or a printable page. Each part is only included when the person
 * exporting may see that kind of data; the file lists what was left out so nothing is silently missing.
 *
 * Note: the app keeps no record of voided cart lines, so voids cannot be listed. Refunds (credit notes),
 * discounts, cash movements and shift cash variances come from the POS records of the employee's login.
 */
final class EmployeeExportService
{
    private const ROW_CAP = 5000;

    /**
     * @return array{title: string, employee: string, period: array{from: string, to: string}, generated: string, sections: list<array{key: string, title: string, columns: list<string>, rows: list<list<mixed>>, note?: string}>, skipped: list<string>}
     */
    public function build(User $actor, Contact $employee): array
    {
        if (! $actor->hasPermission('hr.employees.lifecycle')) {
            throw new BusinessRuleException('You are not allowed to export an employee record.', 'not_permitted');
        }
        $detail = $employee->employeeDetail ?? throw new BusinessRuleException('This employee has no HR record yet.', 'no_detail');
        $employee->loadMissing('user', 'employeeDetail.store', 'employeeDetail.department', 'employeeDetail.position');
        $store = $detail->store ?? Store::query()->first();
        $tz = $store?->timezone() ?? 'Asia/Karachi';
        $from = ($detail->joining_date ?? CarbonImmutable::parse($employee->created_at))->toDateString();
        $to = ($detail->exit_date ?? CarbonImmutable::now($tz))->toDateString();
        $fmt = fn ($ts) => $ts ? ($ts instanceof \DateTimeInterface ? CarbonImmutable::instance($ts) : CarbonImmutable::parse($ts, 'UTC'))->setTimezone($tz)->format('d M Y H:i') : '';
        $day = fn ($d) => $d ? CarbonImmutable::parse($d)->format('d M Y') : '';
        $money = fn ($v) => round((float) $v, 2);
        $user = $employee->user;

        $sections = [];
        $skipped = [];

        // 1. Summary
        $sensitive = $actor->hasPermission('hr.employees.sensitive');
        $profile = [
            ['Name', $employee->name], ['Name (Urdu)', $employee->name_ur ?? ''], ['Employee no.', $detail->employee_no], ['Status', ucfirst($detail->status)],
            ['Store', $store?->name ?? ''], ['Department', $detail->department?->name ?? ''], ['Position', $detail->position?->name ?? $detail->job_title ?? ''],
            ['Employment type', str_replace('_', ' ', (string) $detail->employment_type)], ['Joined (this period)', $day($detail->joining_date)],
            ['Left', $day($detail->exit_date)], ['Reason for leaving', $detail->exit_reason ?? ''],
            ['Period covered', $day($from).' to '.$day($to)], ['Phone', $employee->phone ?? ''], ['E-mail', $employee->email ?? ''],
            ['Login', $user ? $user->email.' ('.$user->status.')' : 'No login'],
        ];
        if ($sensitive) {
            $profile[] = ['CNIC', $detail->cnic ?? ''];
            $profile[] = ['Bank', trim(($detail->bank_name ?? '').' '.($detail->bank_account ?? ''))];
        } else {
            $skipped[] = 'CNIC and bank details (needs permission to see identity and bank details)';
        }
        $sections[] = ['key' => 'summary', 'title' => 'Summary', 'columns' => ['Item', 'Value'], 'rows' => $profile];

        // 2. Employer's insights
        $sections[] = ['key' => 'insights', 'title' => "Employer's insights", 'columns' => ['Written by', 'Written on', 'Insights'], 'rows' => $detail->employer_insights
            ? [[User::query()->find($detail->insights_by)?->name ?? '', $fmt($detail->insights_at), $detail->employer_insights]] : [['', '', '(none written)']]];

        // 3. History in the period
        $events = EmployeeLifecycleEvent::query()->where('contact_id', $employee->id)->whereBetween('effective_date', [$from, $to])->orderBy('effective_date')->orderBy('id')->with('recorder:id,name')->get();
        $sections[] = ['key' => 'history', 'title' => 'Employment history', 'columns' => ['Date', 'Event', 'Note', 'Recorded by'], 'rows' => $events->map(fn ($e) => [$day($e->effective_date), str_replace('_', ' ', $e->type), $e->note ?? '', $e->recorder?->name ?? ''])->all()];

        // 4-5. Attendance and breaks
        if ($actor->hasPermission('hr.attendance.view')) {
            $records = AttendanceRecord::query()->where('contact_id', $employee->id)->whereBetween('work_date', [$from, $to])->orderBy('work_date')->get();
            $sections[] = ['key' => 'attendance', 'title' => 'Attendance', 'columns' => ['Date', 'Status', 'In', 'Out', 'Late (min)', 'Early leave (min)', 'Worked (min)', 'Overtime (min)', 'Breaks (min)', 'Unpaid breaks (min)', 'Over the break limit (min)', 'Corrected'],
                'rows' => $records->map(fn ($r) => [$day($r->work_date), str_replace('_', ' ', $r->status), $fmt($r->clock_in_at), $fmt($r->clock_out_at), $r->late_minutes, $r->early_leave_minutes, $r->worked_minutes, $r->overtime_minutes, (int) $r->break_minutes, (int) $r->unpaid_break_minutes, (int) $r->break_over_minutes, $r->is_corrected ? 'Yes' : ''])->all()];
            $count = fn (string $status) => $records->where('status', $status)->count();
            $sections[0]['rows'] = array_merge($sections[0]['rows'], [
                ['Days present / late', $records->whereIn('status', ['present', 'late'])->count().' / '.$count('late')],
                ['Days absent / half day / on leave', $count('absent').' / '.$count('half_day').' / '.$count('on_leave')],
                ['Total worked (hours)', round($records->sum('worked_minutes') / 60, 1)], ['Total overtime (hours)', round($records->sum('overtime_minutes') / 60, 1)],
                ['Total break time (hours)', round($records->sum('break_minutes') / 60, 1)], ['Days over the break limit', $records->where('break_over_minutes', '>', 0)->count()],
            ]);
            $breaks = AttendanceBreak::query()->where('contact_id', $employee->id)->whereBetween('work_date', [$from, $to])->orderBy('started_at')->with('enteredBy:id,name')->get();
            $sections[] = ['key' => 'breaks', 'title' => 'Breaks', 'columns' => ['Date', 'Type', 'From', 'To', 'Minutes', 'Unpaid', 'Entered by', 'Entered as', 'Note'],
                'rows' => $breaks->map(fn ($b) => [$day($b->work_date), $b->type, $fmt($b->started_at), $fmt($b->ended_at), $b->minutes, $b->is_unpaid ? 'Yes' : 'No', $b->enteredBy?->name ?? '', $b->entered_by_role === 'employee' ? 'the employee' : 'a manager', $b->note ?? ''])->all()];
        } else {
            $skipped[] = 'Attendance and breaks';
        }

        // 6. Leave
        if ($actor->hasPermission('hr.leave.view')) {
            $leave = LeaveRequest::query()->where('contact_id', $employee->id)->where('start_date', '<=', $to)->where('end_date', '>=', $from)->with('leaveType:id,name')->orderBy('start_date')->get();
            $sections[] = ['key' => 'leave', 'title' => 'Leave', 'columns' => ['Type', 'From', 'To', 'Days', 'Status', 'Reason'], 'rows' => $leave->map(fn ($l) => [$l->leaveType?->name ?? '', $day($l->start_date), $day($l->end_date), (float) $l->days, $l->status, $l->reason ?? ''])->all()];
        } else {
            $skipped[] = 'Leave';
        }

        // 7-11. POS records of the employee's login
        if ($actor->hasAnyPermission(['pos.shift.view_all', 'reports.pos'])) {
            if (! $user) {
                $sections[] = ['key' => 'pos', 'title' => 'POS records', 'columns' => ['Note'], 'rows' => [['This employee never had a login, so there are no POS records.']]];
            } else {
                $this->posSections($sections, $user, $from, $to, $day, $fmt, $money);
            }
        } else {
            $skipped[] = 'POS shifts, sales, discounts, refunds and cash movements';
        }

        // 12-14. Payroll, salary, advances
        if ($actor->hasPermission('hr.payroll.view')) {
            $slips = Payslip::query()->where('contact_id', $employee->id)->whereHas('run', fn ($r) => $r->where('period_end', '>=', $from)->where('period_start', '<=', $to))->with('run:id,number,period_start,period_end,status')->get()->sortBy(fn ($p) => $p->run?->period_start);
            $sections[] = ['key' => 'payroll', 'title' => 'Payroll', 'columns' => ['Run', 'From', 'To', 'Days payable', 'Gross', 'Deductions', 'Net', 'Status', 'Paid on'],
                'rows' => $slips->map(fn ($p) => [$p->run?->number ?? '', $day($p->run?->period_start), $day($p->run?->period_end), (float) $p->days_payable, $money($p->gross_pay), $money($p->total_deductions), $money($p->net_pay), $p->status, $fmt($p->paid_at)])->values()->all()];
            $salary = SalaryStructure::query()->where('contact_id', $employee->id)->where('effective_from', '<=', $to)->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $from))->orderBy('effective_from')->get();
            $sections[] = ['key' => 'salary', 'title' => 'Salary history', 'columns' => ['From', 'To', 'Paid', 'Basic', 'Overtime per hour', 'Fixed allowances / deductions'],
                'rows' => $salary->map(fn ($s) => [$day($s->effective_from), $s->effective_to ? $day($s->effective_to) : 'now', $s->pay_basis, $money($s->base_amount), $s->overtime_hourly_rate !== null ? $money($s->overtime_hourly_rate) : '', collect(is_string($s->components) ? json_decode($s->components, true) : ($s->components ?? []))->map(fn ($c) => $c['name'].' '.($c['type'] === 'deduction' ? '-' : '+').$c['amount'])->implode(', ')])->all()];
            $advances = EmployeeAdvance::query()->where('contact_id', $employee->id)->with('repayments')->orderBy('id')->get()
                ->filter(fn ($a) => $a->created_at >= CarbonImmutable::parse($from)->startOfDay() || (float) $a->outstanding() > 0);
            $sections[] = ['key' => 'advances', 'title' => 'Peshgi (advances)', 'columns' => ['Number', 'Amount', 'Status', 'Recovered', 'Still owed', 'Per month', 'Reason'],
                'rows' => $advances->map(fn ($a) => [$a->number, $money($a->amount), $a->status, $money($a->recovered_amount), $money($a->outstanding()), $a->installment_amount !== null ? $money($a->installment_amount) : '', $a->reason ?? ''])->values()->all(),
                'note' => 'Anything still owed is for the final settlement.'];
        } else {
            $skipped[] = 'Payroll, salary and Peshgi (needs permission to see salaries and payroll)';
        }

        // 15. Assets
        $assets = EmployeeAsset::query()->where('contact_id', $employee->id)->where('issued_on', '<=', $to)->where(fn ($q) => $q->whereNull('returned_on')->orWhere('returned_on', '>=', $from))->orderBy('issued_on')->get();
        $sections[] = ['key' => 'assets', 'title' => 'Company items', 'columns' => ['Item', 'Category', 'Qty', 'Value', 'Issued', 'Returned', 'Condition'],
            'rows' => $assets->map(fn ($a) => [$a->description, $a->category, $a->quantity, $a->value !== null ? $money($a->value) : '', $day($a->issued_on), $a->returned_on ? $day($a->returned_on) : 'NOT RETURNED', $a->return_condition ?? ''])->all()];

        // 16. Cases and documents
        if ($actor->hasPermission('hr.cases.manage')) {
            $cases = HrCase::query()->where('contact_id', $employee->id)->whereBetween('created_at', [CarbonImmutable::parse($from)->startOfDay(), CarbonImmutable::parse($to)->endOfDay()])->orderBy('created_at')->get();
            $sections[] = ['key' => 'cases', 'title' => 'Cases and discipline', 'columns' => ['Date', 'Type', 'Title', 'Status', 'Outcome'], 'rows' => $cases->map(fn ($c) => [$day($c->occurred_on ?? $c->created_at), $c->type, $c->title, $c->status, $c->outcome ?? ''])->all()];
        } else {
            $skipped[] = 'Cases and discipline';
        }
        if ($sensitive) {
            $docs = EmployeeDocument::query()->where('contact_id', $employee->id)->orderBy('id')->get(['type', 'title', 'expires_on', 'created_at']);
            $sections[] = ['key' => 'documents', 'title' => 'Documents on file', 'columns' => ['Type', 'Title', 'Expires', 'Uploaded'], 'rows' => $docs->map(fn ($d) => [$d->type, $d->title, $day($d->expires_on), $day($d->created_at)])->all(), 'note' => 'Titles only. The files stay in private storage.'];
        } else {
            $skipped[] = 'Documents on file';
        }

        Audit::record('hr.employee_exported', "Exported the record of {$employee->name} ({$from} to {$to})", $employee);

        return ['title' => 'Employee record: '.$employee->name, 'employee' => $employee->name, 'period' => ['from' => $from, 'to' => $to], 'generated' => CarbonImmutable::now($tz)->format('d M Y H:i').' ('.$tz.')', 'sections' => $sections, 'skipped' => $skipped];
    }

    /** @return string path of a temporary .xlsx file (the caller deletes it) */
    public function toXlsx(array $data): string
    {
        $book = new SimpleXlsx;
        $first = [['Employee record'], [$data['employee']], ['Period', $data['period']['from'].' to '.$data['period']['to']], ['Created', $data['generated']]];
        foreach ($data['skipped'] as $item) {
            $first[] = ['Not included', $item];
        }
        foreach ($data['sections'] as $i => $section) {
            $rows = [array_map(fn ($c) => (string) $c, $section['columns'])];
            foreach ($section['rows'] as $row) {
                $rows[] = $row;
            }
            if ($section['rows'] === []) {
                $rows[] = ['(nothing in this period)'];
            }
            if (! empty($section['note'])) {
                $rows[] = [];
                $rows[] = [$section['note']];
            }
            if ($i === 0) {
                // The Summary sheet also carries the title block.
                $rows = array_merge($first, [[]], $rows);
                $rows[0] = [['b' => 'Employee record']];
            }
            $widths = array_map(fn ($c) => max(12, min(40, mb_strlen((string) $c) + 4)), $section['columns']);
            $widths[0] = max($widths[0] ?? 12, $i === 0 ? 28 : 14);
            if ($section['key'] === 'insights') {
                $widths = [20, 20, 90];
            }
            $book->addSheet($section['title'], $rows, $widths);
        }

        return $book->save();
    }

    /**
     * @param  list<array<string, mixed>>  $sections
     */
    private function posSections(array &$sections, User $user, string $from, string $to, \Closure $day, \Closure $fmt, \Closure $money): void
    {
        $shifts = Shift::query()->where('opened_by', $user->id)->whereBetween('business_date', [$from, $to])->with('register:id,name')->orderBy('opened_at')->limit(self::ROW_CAP)->get();
        $sections[] = ['key' => 'shifts', 'title' => 'Cash shifts', 'columns' => ['Shift', 'Register', 'Date', 'Opened', 'Closed', 'Expected cash', 'Counted cash', 'Difference', 'Review'],
            'rows' => $shifts->map(fn ($s) => [$s->number, $s->register?->name ?? '', $day($s->business_date), $fmt($s->opened_at), $fmt($s->closed_at), $s->expected_cash !== null ? $money($s->expected_cash) : '', $s->counted_cash !== null ? $money($s->counted_cash) : '', $s->variance !== null ? $money($s->variance) : '', str_replace('_', ' ', $s->variance_status)])->all(),
            'note' => 'Difference is counted minus expected cash: below zero is a shortage. Shifts are listed for the person who opened them.'];

        $months = [];
        foreach (SalesInvoice::query()->where('cashier_id', $user->id)->where('status', 'posted')->whereBetween('business_date', [$from, $to])->orderBy('business_date')->cursor() as $invoice) {
            $key = CarbonImmutable::parse($invoice->business_date)->format('Y-m');
            $m = &$months[$key];
            $m ??= ['count' => 0, 'total' => 0.0, 'discount' => 0.0, 'gross' => 0.0];
            $m['count']++;
            $m['total'] += (float) $invoice->total;
            $m['discount'] += (float) $invoice->discount_total;
            $m['gross'] += (float) $invoice->subtotal;
            unset($m);
        }
        $rows = [];
        $tot = ['count' => 0, 'total' => 0.0, 'discount' => 0.0, 'gross' => 0.0];
        foreach ($months as $key => $m) {
            $rows[] = [CarbonImmutable::parse($key.'-01')->format('M Y'), $m['count'], $money($m['total']), $money($m['discount']), $m['gross'] > 0 ? round($m['discount'] / $m['gross'] * 100, 1) : 0.0];
            foreach ($tot as $k => $_) {
                $tot[$k] += $m[$k];
            }
        }
        if ($rows !== []) {
            $rows[] = [['b' => 'Total'], $tot['count'], $money($tot['total']), $money($tot['discount']), $tot['gross'] > 0 ? round($tot['discount'] / $tot['gross'] * 100, 1) : 0.0];
        }
        $sections[] = ['key' => 'sales', 'title' => 'Sales and discounts', 'columns' => ['Month', 'Sales rung up', 'Sales amount', 'Discounts given', 'Discount % of price'], 'rows' => $rows];

        $refunds = CreditNote::query()->where('posted_by', $user->id)->whereBetween('business_date', [$from, $to])->with('invoice:id,number')->orderBy('business_date')->limit(self::ROW_CAP)->get();
        $sections[] = ['key' => 'refunds', 'title' => 'Refunds and returns', 'columns' => ['Credit note', 'Date', 'Receipt', 'Paid back as', 'Amount'],
            'rows' => $refunds->map(fn ($c) => [$c->number, $day($c->business_date), $c->invoice?->number ?? '', $c->settlement === 'refund' ? 'cash/refund' : 'Khata credit', $money($c->total)])->all(), 'note' => 'The app does not keep voided cart lines, so voids are not listed.'];

        $moves = CashMovement::query()->where('user_id', $user->id)->whereBetween('business_date', [$from, $to])->with('reasonCode:id,name', 'approver:id,name')->orderBy('occurred_at')->limit(self::ROW_CAP)->get();
        $sections[] = ['key' => 'cash', 'title' => 'Cash movements', 'columns' => ['Number', 'Type', 'When', 'Amount', 'Reason', 'Note', 'Approved by'],
            'rows' => $moves->map(fn ($c) => [$c->number, str_replace('_', ' ', $c->type), $fmt($c->occurred_at), $money($c->amount), $c->reasonCode?->name ?? '', $c->note ?? '', $c->approver?->name ?? ''])->all()];

        $approvals = Approval::query()->where('requested_by', $user->id)->whereBetween('created_at', [CarbonImmutable::parse($from)->startOfDay(), CarbonImmutable::parse($to)->endOfDay()])->get(['action']);
        $sections[] = ['key' => 'approvals', 'title' => 'Manager approvals asked for', 'columns' => ['Kind', 'Times'], 'rows' => $approvals->groupBy('action')->map(fn ($g, $action) => [str_replace(['_', '.'], ' ', (string) $action), $g->count()])->values()->all()];
    }
}
