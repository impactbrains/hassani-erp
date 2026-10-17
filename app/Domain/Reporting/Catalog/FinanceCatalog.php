<?php

namespace App\Domain\Reporting\Catalog;

use App\Domain\Reporting\DefinedReport;
use App\Domain\Reporting\ReportFilters as F;
use App\Support\Decimal;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;

/** Financial statements, HR, audit and forecast reports. */
final class FinanceCatalog
{
    /**
     * Posted journal lines for the user's stores. Company-level lines (no
     * store, e.g. payroll for head office) are included as well.
     */
    private static function lines(F $f)
    {
        return DB::table('journal_lines as jl')->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')->join('accounts as a', 'a.id', '=', 'jl.account_id')
            ->where('je.status', 'posted')->where('jl.company_id', CurrentCompany::require())
            ->where(fn ($q) => $q->whereIn('jl.store_id', $f->storeIds)->orWhereNull('jl.store_id'));
    }

    private static function sign(string $type): string
    {
        return in_array($type, ['asset', 'expense'], true) ? 'jl.debit - jl.credit' : 'jl.credit - jl.debit';
    }

    /** @return list<array<string, mixed>> */
    public static function reports(): array
    {
        $money = fn (string $label, array $extra = []) => ['label' => $label, 'type' => 'money'] + $extra;
        $glDef = fn (string $basis) => ['source' => 'Posted journal entries (every sale, purchase, payment, stock change and payroll posts automatically)', 'date_basis' => $basis, 'limitations' => ['Company-level entries without a store are included in every store selection.']];

        return [
            [
                'key' => 'finance.profit_loss', 'section' => 'finance', 'title' => 'Profit and loss', 'title_ur' => 'نفع و نقصان', 'permission' => 'reports.finance',
                'description' => 'Income, cost of sales and expenses for the period from the accounts, with the comparison period.', 'comparison' => true,
                'definition' => $glDef('Entry date') + ['definitions' => ['Gross profit' => 'Income − cost of sales accounts (5xxx).', 'Net profit' => 'Gross profit − operating expenses (6xxx).']],
                'columns' => ['code' => ['label' => 'Code', 'type' => 'text'], 'account' => ['label' => 'Account', 'type' => 'text'], 'section' => ['label' => 'Section', 'type' => 'text'], 'amount' => $money('This period'), 'previous' => $money('Comparison'), 'account_id' => ['label' => 'id', 'type' => 'hidden']],
                'rows' => function (F $f) {
                    $cmp = $f->comparison();
                    $period = fn (string $from, string $to) => "SUM(CASE WHEN jl.entry_date BETWEEN '{$from}' AND '{$to}' THEN (CASE WHEN a.type = 'income' THEN jl.credit - jl.debit ELSE jl.debit - jl.credit END) ELSE 0 END)";

                    return self::lines($f)->whereIn('a.type', ['income', 'expense'])
                        ->whereBetween('jl.entry_date', [$cmp ? min($cmp->from, $f->from) : $f->from, $f->to])
                        ->groupBy('a.id', 'a.code', 'a.name', 'a.type', 'a.subtype')
                        ->selectRaw("a.code, a.name account, CASE WHEN a.type = 'income' THEN '1 Income' WHEN a.subtype = 'cogs' THEN '2 Cost of sales' ELSE '3 Expenses' END section,
                            {$period($f->from, $f->to)} amount, ".($cmp ? $period($cmp->from, $cmp->to) : 'NULL::numeric').' previous, a.id account_id');
                },
                'summary' => function (F $f) {
                    $calc = function (F $p) {
                        $r = self::lines($p)->whereIn('a.type', ['income', 'expense'])->whereBetween('jl.entry_date', [$p->from, $p->to])
                            ->selectRaw("COALESCE(SUM(CASE WHEN a.type='income' THEN jl.credit - jl.debit ELSE 0 END),0) income, COALESCE(SUM(CASE WHEN a.subtype='cogs' THEN jl.debit - jl.credit ELSE 0 END),0) cos,
                                COALESCE(SUM(CASE WHEN a.type='expense' AND COALESCE(a.subtype,'') <> 'cogs' THEN jl.debit - jl.credit ELSE 0 END),0) opex")->first();
                        $gross = Decimal::sub($r->income, $r->cos);

                        return ['income' => Decimal::money($r->income), 'gross' => Decimal::money($gross), 'net' => Decimal::money(Decimal::sub($gross, $r->opex))];
                    };
                    $now = $calc($f);
                    $prev = $f->comparison() ? $calc($f->comparison()) : [];

                    return [SalesCatalog::card('income', 'Income', $now['income'], 'money', $prev['income'] ?? null), SalesCatalog::card('gross', 'Gross profit', $now['gross'], 'money', $prev['gross'] ?? null), SalesCatalog::card('net', 'Net profit', $now['net'], 'money', $prev['net'] ?? null)];
                },
                'insights' => fn (F $f, array $s) => SalesCatalog::changeInsights($f, $s),
                'drill' => ['column' => 'account', 'route' => 'accounting.accounts.show', 'param' => 'account_id'],
                'sort' => ['code', 'asc'],
            ],
            [
                'key' => 'finance.balance_sheet', 'section' => 'finance', 'title' => 'Balance sheet', 'title_ur' => 'بیلنس شیٹ', 'permission' => 'reports.finance',
                'description' => 'What the business owns and owes on the end date. Profit not yet closed to retained earnings is shown as current earnings.',
                'filters' => ['period', 'stores'],
                'definition' => $glDef('Balances up to and including the end date') + ['definitions' => ['Check' => 'Assets − liabilities − equity − current earnings = 0.']],
                'columns' => ['section' => ['label' => 'Section', 'type' => 'text'], 'code' => ['label' => 'Code', 'type' => 'text'], 'account' => ['label' => 'Account', 'type' => 'text'], 'balance' => $money('Balance'), 'account_id' => ['label' => 'id', 'type' => 'hidden']],
                'rows' => fn (F $f) => DB::query()->fromSub(self::lines($f)->where('jl.entry_date', '<=', $f->to)->whereIn('a.type', ['asset', 'liability', 'equity'])->groupBy('a.id', 'a.code', 'a.name', 'a.type')
                    ->selectRaw("CASE a.type WHEN 'asset' THEN '1 Assets' WHEN 'liability' THEN '2 Liabilities' ELSE '3 Equity' END section, a.code, a.name account,
                        SUM(CASE WHEN a.type = 'asset' THEN jl.debit - jl.credit ELSE jl.credit - jl.debit END) balance, a.id account_id")
                    ->unionAll(self::lines($f)->where('jl.entry_date', '<=', $f->to)->whereIn('a.type', ['income', 'expense'])
                        ->selectRaw("'3 Equity' section, '3999' code, 'Current earnings (not yet closed)' account, COALESCE(SUM(jl.credit - jl.debit),0) balance, NULL::bigint account_id")), 'x')
                    ->where('balance', '!=', 0),
                'summary' => function (F $f) {
                    $r = self::lines($f)->where('jl.entry_date', '<=', $f->to)->selectRaw("COALESCE(SUM(CASE WHEN a.type='asset' THEN jl.debit - jl.credit ELSE 0 END),0) assets,
                        COALESCE(SUM(CASE WHEN a.type='liability' THEN jl.credit - jl.debit ELSE 0 END),0) liabilities, COALESCE(SUM(CASE WHEN a.type IN ('equity','income','expense') THEN jl.credit - jl.debit ELSE 0 END),0) equity")->first();
                    $check = Decimal::money(Decimal::sub(Decimal::sub($r->assets, $r->liabilities), $r->equity));

                    return [SalesCatalog::card('assets', 'Assets', Decimal::money($r->assets), 'money'), SalesCatalog::card('liabilities', 'Liabilities', Decimal::money($r->liabilities), 'money'),
                        SalesCatalog::card('equity', 'Equity incl. current earnings', Decimal::money($r->equity), 'money', null, Decimal::isZero($check) ? 'Balances' : "Out by Rs {$check}")];
                },
                'drill' => ['column' => 'account', 'route' => 'accounting.accounts.show', 'param' => 'account_id'],
                'sort' => ['code', 'asc'],
            ],
            [
                'key' => 'finance.trial_balance', 'section' => 'finance', 'title' => 'Trial balance', 'permission' => 'reports.finance',
                'description' => 'Opening balance, period debits and credits and closing balance of every account. Total debits always equal total credits.',
                'definition' => $glDef('Opening = before start date; movements within the period'),
                'columns' => ['code' => ['label' => 'Code', 'type' => 'text'], 'account' => ['label' => 'Account', 'type' => 'text'], 'type' => ['label' => 'Type', 'type' => 'text'], 'opening' => $money('Opening (Dr+ / Cr−)'), 'debit' => $money('Debits'), 'credit' => $money('Credits'), 'closing' => $money('Closing (Dr+ / Cr−)'), 'account_id' => ['label' => 'id', 'type' => 'hidden']],
                'rows' => fn (F $f) => self::lines($f)->where('jl.entry_date', '<=', $f->to)->groupBy('a.id', 'a.code', 'a.name', 'a.type')
                    ->selectRaw("a.code, a.name account, a.type, SUM(CASE WHEN jl.entry_date < '{$f->from}' THEN jl.debit - jl.credit ELSE 0 END) opening,
                        SUM(CASE WHEN jl.entry_date >= '{$f->from}' THEN jl.debit ELSE 0 END) debit, SUM(CASE WHEN jl.entry_date >= '{$f->from}' THEN jl.credit ELSE 0 END) credit, SUM(jl.debit - jl.credit) closing, a.id account_id"),
                'summary' => function (F $f) {
                    $r = self::lines($f)->whereBetween('jl.entry_date', [$f->from, $f->to])->selectRaw('COALESCE(SUM(jl.debit),0) d, COALESCE(SUM(jl.credit),0) c')->first();

                    return [SalesCatalog::card('debits', 'Total debits', Decimal::money($r->d), 'money'), SalesCatalog::card('credits', 'Total credits', Decimal::money($r->c), 'money', null, Decimal::eq($r->d, $r->c) ? 'In balance' : 'NOT in balance')];
                },
                'drill' => ['column' => 'account', 'route' => 'accounting.accounts.show', 'param' => 'account_id'],
                'sort' => ['code', 'asc'],
            ],
            [
                'key' => 'finance.general_ledger', 'section' => 'finance', 'title' => 'General ledger', 'title_ur' => 'کھاتہ عام', 'permission' => 'reports.finance',
                'description' => 'Every posted journal line with its source document. Filter by account to see one account\'s history.',
                'filters' => ['period', 'stores', 'account', 'search'],
                'definition' => $glDef('Entry date'),
                'columns' => ['date' => ['label' => 'Date', 'type' => 'date'], 'entry' => ['label' => 'Entry', 'type' => 'text'], 'code' => ['label' => 'Code', 'type' => 'text'], 'account' => ['label' => 'Account', 'type' => 'text'], 'description' => ['label' => 'Description', 'type' => 'text'], 'party' => ['label' => 'Customer / supplier', 'type' => 'text'], 'debit' => $money('Debit'), 'credit' => $money('Credit'), 'entry_id' => ['label' => 'id', 'type' => 'hidden']],
                'rows' => fn (F $f) => self::lines($f)->leftJoin('contacts as c', 'c.id', '=', 'jl.contact_id')->whereBetween('jl.entry_date', [$f->from, $f->to])
                    ->when($f->entity('account_id'), fn ($q, $id) => $q->where('a.id', $id))
                    ->when($f->search, fn ($q, $s) => $q->where(fn ($w) => $w->where('je.description', 'ilike', "%{$s}%")->orWhere('je.number', 'ilike', "%{$s}%")))
                    ->selectRaw('jl.entry_date date, je.number entry, a.code, a.name account, COALESCE(jl.memo, je.description) description, c.name party, jl.debit, jl.credit, je.id entry_id'),
                'drill' => ['column' => 'entry', 'route' => 'accounting.journals.show', 'param' => 'entry_id'],
                'sort' => ['date', 'asc'],
            ],
            [
                'key' => 'finance.cash_book', 'section' => 'finance', 'title' => 'Cash, bank and wallet movement', 'title_ur' => 'کیش بک', 'permission' => 'reports.finance',
                'description' => 'Opening balance, money in, money out and closing balance of every cash, bank, card-clearing and wallet account.',
                'definition' => $glDef('Entry date'),
                'columns' => ['account' => ['label' => 'Account', 'type' => 'text'], 'opening' => $money('Opening'), 'money_in' => $money('In'), 'money_out' => $money('Out'), 'closing' => $money('Closing'), 'account_id' => ['label' => 'id', 'type' => 'hidden']],
                'rows' => fn (F $f) => self::lines($f)->whereIn('a.subtype', ['cash', 'bank', 'wallet', 'clearing'])->where('jl.entry_date', '<=', $f->to)->groupBy('a.id', 'a.code', 'a.name')
                    ->selectRaw("a.code || ' ' || a.name account, SUM(CASE WHEN jl.entry_date < '{$f->from}' THEN jl.debit - jl.credit ELSE 0 END) opening, SUM(CASE WHEN jl.entry_date >= '{$f->from}' THEN jl.debit ELSE 0 END) money_in,
                        SUM(CASE WHEN jl.entry_date >= '{$f->from}' THEN jl.credit ELSE 0 END) money_out, SUM(jl.debit - jl.credit) closing, a.id account_id"),
                'drill' => ['column' => 'account', 'route' => 'accounting.accounts.show', 'param' => 'account_id'],
                'sort' => ['account', 'asc'],
            ],
            [
                'key' => 'finance.tax', 'section' => 'finance', 'title' => 'Sales tax summary', 'permission' => 'reports.finance',
                'description' => 'Output tax on sales (less returns) and input tax on purchases by month. For record-keeping until FBR integration is set up.',
                'definition' => ['source' => 'Sales tax payable and input tax accounts', 'date_basis' => 'Entry date', 'limitations' => ['Not an FBR return. See README → FBR for the migration path.']],
                'columns' => ['month' => ['label' => 'Month', 'type' => 'text'], 'output_tax' => $money('Output tax'), 'input_tax' => $money('Input tax'), 'net_payable' => $money('Net payable')],
                'rows' => fn (F $f) => self::lines($f)->whereIn('a.system_key', ['sales_tax_payable', 'input_tax'])->whereBetween('jl.entry_date', [$f->from, $f->to])->groupByRaw("to_char(jl.entry_date, 'YYYY-MM')")
                    ->selectRaw("to_char(jl.entry_date, 'YYYY-MM') AS month, SUM(CASE WHEN a.system_key = 'sales_tax_payable' THEN jl.credit - jl.debit ELSE 0 END) output_tax, SUM(CASE WHEN a.system_key = 'input_tax' THEN jl.debit - jl.credit ELSE 0 END) input_tax,
                        SUM(CASE WHEN a.system_key = 'sales_tax_payable' THEN jl.credit - jl.debit ELSE jl.credit - jl.debit END) net_payable"),
                'sort' => ['month', 'asc'],
            ],
            [
                'key' => 'finance.expenses', 'section' => 'finance', 'title' => 'Expenses and budget', 'title_ur' => 'اخراجات', 'permission' => 'reports.finance',
                'description' => 'Operating expenses by account against the monthly budget, with the variance.',
                'definition' => $glDef('Entry date') + ['definitions' => ['Budget' => 'Sum of monthly budgets for months overlapping the period.', 'Variance' => 'Actual − budget (positive = over budget).']],
                'columns' => ['account' => ['label' => 'Account', 'type' => 'text'], 'actual' => $money('Actual'), 'budget' => $money('Budget'), 'variance' => $money('Over / (under)'), 'used' => ['label' => 'Used %', 'type' => 'percent'], 'account_id' => ['label' => 'id', 'type' => 'hidden']],
                'rows' => fn (F $f, DefinedReport $r) => DB::query()->fromSub(DB::table('accounts as a')->where('a.company_id', CurrentCompany::require())->where('a.type', 'expense')->where('a.is_postable', true)
                    ->selectRaw("a.code || ' ' || a.name account, a.id account_id")
                    ->selectSub(fn ($q) => $q->from('journal_lines as jl')->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')->whereColumn('jl.account_id', 'a.id')->where('je.status', 'posted')
                        ->whereBetween('jl.entry_date', [$f->from, $f->to])->where(fn ($w) => $w->whereIn('jl.store_id', $f->storeIds)->orWhereNull('jl.store_id'))->selectRaw('COALESCE(SUM(jl.debit - jl.credit),0)'), 'actual')
                    ->selectSub(fn ($q) => $q->from('budgets')->whereColumn('account_id', 'a.id')->whereBetween('month', [substr($f->from, 0, 7).'-01', $f->to])->where(fn ($w) => $w->whereIn('store_id', $f->storeIds)->orWhereNull('store_id'))->selectRaw('SUM(amount)'), 'budget'), 'x')
                    ->whereRaw('actual <> 0 OR budget IS NOT NULL')
                    ->selectRaw('account, actual, budget, actual - budget variance, '.$r->pctSql('actual', 'budget').' used, account_id'),
                'drill' => ['column' => 'account', 'route' => 'accounting.accounts.show', 'param' => 'account_id'],
                'sort' => ['actual', 'desc'],
            ],
            // ---------- HR ----------
            [
                'key' => 'hr.headcount', 'section' => 'hr', 'title' => 'Headcount', 'title_ur' => 'ملازمین', 'permission' => 'reports.hr',
                'description' => 'Employees by store, department and status, with joiners and leavers in the period.',
                'filters' => ['period', 'stores'],
                'definition' => ['source' => 'Employee records', 'date_basis' => 'Joining / exit date for movement; status as of now', 'definitions' => ['Turnover' => 'Leavers ÷ average of headcount at start and end of the period.']],
                'columns' => ['store' => ['label' => 'Store', 'type' => 'text'], 'department' => ['label' => 'Department', 'type' => 'text'], 'active' => ['label' => 'Active', 'type' => 'int'], 'probation' => ['label' => 'Onboarding / probation', 'type' => 'int'], 'suspended' => ['label' => 'Suspended', 'type' => 'int'], 'joined' => ['label' => 'Joined in period', 'type' => 'int'], 'left' => ['label' => 'Left in period', 'type' => 'int']],
                'rows' => fn (F $f) => DB::table('employee_details as e')->leftJoin('stores as s', 's.id', '=', 'e.store_id')->leftJoin('departments as d', 'd.id', '=', 'e.department_id')
                    ->where('e.company_id', CurrentCompany::require())->where(fn ($q) => $q->whereIn('e.store_id', $f->storeIds)->orWhereNull('e.store_id'))->groupBy('s.name', 'd.name')
                    ->selectRaw("COALESCE(s.name, 'Head office') store, COALESCE(d.name, '-') department, SUM(CASE WHEN e.status = 'active' THEN 1 ELSE 0 END) active,
                        SUM(CASE WHEN e.status IN ('onboarding','probation') THEN 1 ELSE 0 END) probation, SUM(CASE WHEN e.status = 'suspended' THEN 1 ELSE 0 END) suspended,
                        SUM(CASE WHEN e.joining_date BETWEEN '{$f->from}' AND '{$f->to}' THEN 1 ELSE 0 END) joined, SUM(CASE WHEN e.exit_date BETWEEN '{$f->from}' AND '{$f->to}' THEN 1 ELSE 0 END) \"left\""),
                'summary' => function (F $f) {
                    $base = DB::table('employee_details')->where('company_id', CurrentCompany::require())->where(fn ($q) => $q->whereIn('store_id', $f->storeIds)->orWhereNull('store_id'));
                    $at = fn (string $date) => (clone $base)->where('joining_date', '<=', $date)->where(fn ($q) => $q->whereNull('exit_date')->orWhere('exit_date', '>', $date))->count();
                    $leavers = (clone $base)->whereBetween('exit_date', [$f->from, $f->to])->count();
                    $avg = ($at($f->from) + $at($f->to)) / 2;

                    return [SalesCatalog::card('headcount', 'Headcount (end date)', $at($f->to), 'int'), SalesCatalog::card('leavers', 'Leavers', $leavers, 'int'), SalesCatalog::card('turnover', 'Turnover', $avg > 0 ? round(100 * $leavers / $avg, 1) : null, 'percent')];
                },
                'sort' => ['store', 'asc'],
            ],
            [
                'key' => 'hr.attendance', 'section' => 'hr', 'title' => 'Attendance summary', 'title_ur' => 'حاضری', 'permission' => 'reports.hr',
                'description' => 'Per employee: days present, absent, late, on leave, hours worked and overtime in the period.',
                'filters' => ['period', 'stores', 'search'],
                'definition' => ['source' => 'Attendance records (after approved corrections)', 'date_basis' => 'Work date', 'limitations' => ['Late and absent counts are evidence for review, not automatic penalties.']],
                'columns' => ['employee' => ['label' => 'Employee', 'type' => 'text'], 'store' => ['label' => 'Store', 'type' => 'text'], 'present' => ['label' => 'Present', 'type' => 'int'], 'absent' => ['label' => 'Absent', 'type' => 'int'], 'leave' => ['label' => 'Leave', 'type' => 'int'], 'late' => ['label' => 'Late', 'type' => 'int'], 'late_minutes' => ['label' => 'Late minutes', 'type' => 'int'], 'hours' => ['label' => 'Hours worked', 'type' => 'decimal'], 'overtime_hours' => ['label' => 'Overtime hours', 'type' => 'decimal'], 'corrected' => ['label' => 'Corrected', 'type' => 'int']],
                'rows' => fn (F $f) => DB::table('attendance_records as r')->join('contacts as c', 'c.id', '=', 'r.contact_id')->leftJoin('stores as s', 's.id', '=', 'r.store_id')
                    ->whereBetween('r.work_date', [$f->from, $f->to])->whereIn('r.store_id', $f->storeIds)->when($f->search, fn ($q, $s) => $q->where('c.name', 'ilike', "%{$s}%"))->groupBy('c.id', 'c.name', 's.name')
                    ->selectRaw("c.name employee, s.name store, SUM(CASE WHEN r.status IN ('present','late','half_day') THEN 1 ELSE 0 END) present, SUM(CASE WHEN r.status = 'absent' THEN 1 ELSE 0 END) absent,
                        SUM(CASE WHEN r.status = 'on_leave' THEN 1 ELSE 0 END) leave, SUM(CASE WHEN r.late_minutes > 0 THEN 1 ELSE 0 END) late, SUM(r.late_minutes) late_minutes,
                        ROUND(SUM(r.worked_minutes) / 60.0, 1) hours, ROUND(SUM(r.overtime_minutes) / 60.0, 1) overtime_hours, SUM(CASE WHEN r.is_corrected THEN 1 ELSE 0 END) corrected"),
                'sort' => ['employee', 'asc'],
            ],
            [
                'key' => 'hr.leave', 'section' => 'hr', 'title' => 'Leave taken and balances', 'permission' => 'reports.hr',
                'description' => 'Leave requests by employee and type with days taken, pending and remaining allocation.',
                'filters' => ['period', 'search'],
                'definition' => ['source' => 'Leave requests and allocations', 'date_basis' => 'Leave start date'],
                'columns' => ['employee' => ['label' => 'Employee', 'type' => 'text'], 'leave_type' => ['label' => 'Type', 'type' => 'text'], 'approved_days' => ['label' => 'Days taken', 'type' => 'decimal'], 'pending_days' => ['label' => 'Pending', 'type' => 'decimal'], 'rejected' => ['label' => 'Rejected requests', 'type' => 'int']],
                'rows' => fn (F $f) => DB::table('leave_requests as l')->join('contacts as c', 'c.id', '=', 'l.contact_id')->join('leave_types as t', 't.id', '=', 'l.leave_type_id')
                    ->where('l.company_id', CurrentCompany::require())->whereBetween('l.start_date', [$f->from, $f->to])->when($f->search, fn ($q, $s) => $q->where('c.name', 'ilike', "%{$s}%"))->groupBy('c.name', 't.name')
                    ->selectRaw("c.name employee, t.name leave_type, SUM(CASE WHEN l.status = 'approved' THEN l.days ELSE 0 END) approved_days, SUM(CASE WHEN l.status = 'pending' THEN l.days ELSE 0 END) pending_days, SUM(CASE WHEN l.status = 'rejected' THEN 1 ELSE 0 END) rejected"),
                'sort' => ['employee', 'asc'],
            ],
            [
                'key' => 'hr.payroll', 'section' => 'hr', 'title' => 'Payroll cost', 'title_ur' => 'تنخواہ', 'permission' => 'hr.payroll.view',
                'description' => 'Each payroll run: employees paid, gross pay, deductions, net pay and employer contributions.',
                'filters' => ['period', 'stores'],
                'definition' => ['source' => 'Payroll runs and payslips', 'date_basis' => 'Payroll period end', 'definitions' => ['Employer cost' => 'Gross pay + employer EOBI / social security.']],
                'columns' => ['number' => ['label' => 'Run', 'type' => 'text'], 'period' => ['label' => 'Period', 'type' => 'text'], 'store' => ['label' => 'Store', 'type' => 'text'], 'status' => ['label' => 'Status', 'type' => 'status'], 'employees' => ['label' => 'Employees', 'type' => 'int'], 'gross' => $money('Gross'), 'deductions' => $money('Deductions'), 'net' => $money('Net pay'), 'employer_cost' => $money('Employer cost')],
                'rows' => fn (F $f) => DB::table('payroll_runs as r')->leftJoin('stores as s', 's.id', '=', 'r.store_id')->where('r.company_id', CurrentCompany::require())
                    ->where(fn ($q) => $q->whereIn('r.store_id', $f->storeIds)->orWhereNull('r.store_id'))->whereBetween('r.period_end', [$f->from, $f->to])
                    ->selectRaw("r.number, r.period_start || ' to ' || r.period_end period, COALESCE(s.name, 'All stores') store, r.status, (SELECT COUNT(*) FROM payslips WHERE payroll_run_id = r.id) employees,
                        r.gross_total gross, r.deduction_total deductions, r.net_total net, r.employer_cost_total employer_cost"),
                'sort' => ['period', 'desc'],
            ],
            [
                'key' => 'hr.advances', 'section' => 'hr', 'title' => 'Peshgi (advances) outstanding', 'title_ur' => 'پیشگی', 'permission' => 'hr.payroll.view',
                'description' => 'Advances given to employees, recovered so far through payroll and still outstanding.',
                'filters' => ['search'],
                'definition' => ['source' => 'Employee advances', 'date_basis' => 'As of now'],
                'columns' => ['number' => ['label' => 'Advance', 'type' => 'text'], 'employee' => ['label' => 'Employee', 'type' => 'text'], 'date' => ['label' => 'Given', 'type' => 'date'], 'amount' => $money('Amount'), 'installment' => $money('Per month'), 'recovered' => $money('Recovered'), 'outstanding' => $money('Outstanding'), 'status' => ['label' => 'Status', 'type' => 'status']],
                'rows' => fn (F $f) => DB::table('employee_advances as a')->join('contacts as c', 'c.id', '=', 'a.contact_id')->where('a.company_id', CurrentCompany::require())
                    ->whereIn('a.status', ['disbursed', 'settled'])->when($f->search, fn ($q, $s) => $q->where('c.name', 'ilike', "%{$s}%"))
                    ->selectRaw('a.number, c.name employee, a.business_date date, a.amount, a.installment_amount installment, a.recovered_amount recovered, a.amount - a.recovered_amount outstanding, a.status'),
                'sort' => ['outstanding', 'desc'],
            ],
            // ---------- audit ----------
            [
                'key' => 'audit.exceptions', 'section' => 'audit', 'title' => 'Exceptions and sensitive actions', 'title_ur' => 'استثنائی کارروائیاں', 'permission' => 'reports.audit',
                'description' => 'Price overrides, discounts, removed lines, discarded carts, credit-limit overrides, rejected payments, write-offs, role and setting changes: what happened, who did it and who approved.',
                'filters' => ['period', 'stores', 'search'],
                'definition' => ['source' => 'Audit log (append-only)', 'date_basis' => 'Time of the action, business time zone', 'limitations' => ['An exception is something to look at, not proof of wrongdoing.', 'Posted receipts can never be deleted; corrections are credit notes, so "deleted receipts" appear here as discarded carts and removed lines.']],
                'columns' => ['at' => ['label' => 'When', 'type' => 'datetime'], 'event' => ['label' => 'Action', 'type' => 'status'], 'description' => ['label' => 'Details', 'type' => 'text'], 'user' => ['label' => 'By', 'type' => 'text'], 'store' => ['label' => 'Store', 'type' => 'text'], 'ip' => ['label' => 'IP', 'type' => 'text']],
                'rows' => fn (F $f) => DB::table('audit_logs as l')->leftJoin('users as u', 'u.id', '=', 'l.user_id')->leftJoin('stores as s', 's.id', '=', 'l.store_id')
                    ->where('l.company_id', CurrentCompany::require())->where(fn ($q) => $q->whereIn('l.store_id', $f->storeIds)->orWhereNull('l.store_id'))
                    ->whereBetween(DB::raw("(l.created_at AT TIME ZONE '{$f->timezone}')::date"), [$f->from, $f->to])
                    ->whereIn('l.event', self::EXCEPTION_EVENTS)
                    ->when($f->option('event'), fn ($q, $e) => $q->where('l.event', $e))
                    ->when($f->search, fn ($q, $s) => $q->where(fn ($w) => $w->where('l.description', 'ilike', "%{$s}%")->orWhere('u.name', 'ilike', "%{$s}%")))
                    ->selectRaw('l.created_at at, l.event, l.description, u.name "user", s.name store, l.ip_address ip'),
                'summary' => fn (F $f) => DB::table('audit_logs')->where('company_id', CurrentCompany::require())->whereIn('event', self::EXCEPTION_EVENTS)
                    ->whereBetween(DB::raw("(created_at AT TIME ZONE '{$f->timezone}')::date"), [$f->from, $f->to])->where(fn ($q) => $q->whereIn('store_id', $f->storeIds)->orWhereNull('store_id'))
                    ->groupBy('event')->orderByDesc(DB::raw('COUNT(*)'))->limit(6)->selectRaw('event, COUNT(*) n')->get()
                    ->map(fn ($r) => SalesCatalog::card($r->event, str_replace(['_', '.'], [' ', ': '], $r->event), (int) $r->n, 'int'))->all(),
                'sort' => ['at', 'desc'],
            ],
            // ---------- forecasts ----------
            [
                'key' => 'forecasts.demand', 'section' => 'forecasts', 'title' => 'Demand forecast', 'title_ur' => 'طلب کی پیش گوئی', 'permission' => 'reports.forecasts',
                'description' => 'Expected units per week for the next 4 weeks with a range, the method chosen by backtesting, its measured error and quality. Use as guidance next to the reorder report.',
                'filters' => ['stores', 'search'],
                'definition' => ['source' => 'Latest forecast run per store (weekly sales history, out-of-stock weeks excluded)', 'date_basis' => 'Weeks starting Monday',
                    'definitions' => ['WAPE' => 'Weighted absolute % error over the last 8 weeks when each week was predicted from the weeks before it. Lower is better.', 'Range' => '80% interval: actual sales should fall inside about 4 weeks in 5.', 'Quality' => 'validated (16+ weeks, WAPE ≤ 50%), experimental, or insufficient history (no forecast).'],
                    'limitations' => ['Promotions, Ramadan/Eid and price changes are not modelled yet; seasonal naive only starts after 60 weeks of history.', 'Nothing is ordered automatically from a forecast.']],
                'columns' => ['store' => ['label' => 'Store', 'type' => 'text'], 'product' => ['label' => 'Product', 'type' => 'text'], 'quality' => ['label' => 'Quality', 'type' => 'status'], 'method' => ['label' => 'Method', 'type' => 'text'], 'wape' => ['label' => 'Error (WAPE %)', 'type' => 'percent'], 'next_4_weeks' => ['label' => 'Next 4 weeks', 'type' => 'qty'], 'low' => ['label' => 'Low', 'type' => 'qty'], 'high' => ['label' => 'High', 'type' => 'qty'], 'available' => ['label' => 'Available now', 'type' => 'qty'], 'trend' => ['label' => 'Trend %', 'type' => 'percent'], 'history' => ['label' => 'Weeks of history', 'type' => 'int']],
                'rows' => fn (F $f) => DB::table('forecast_series as fs')->join('forecast_runs as fr', 'fr.id', '=', 'fs.forecast_run_id')->join('stores as s', 's.id', '=', 'fs.store_id')
                    ->join('product_variants as v', 'v.id', '=', 'fs.level_id')->join('products as p', 'p.id', '=', 'v.product_id')
                    ->whereIn('fs.store_id', $f->storeIds)->whereRaw('fr.id = (SELECT MAX(id) FROM forecast_runs WHERE store_id = fs.store_id)')
                    ->when($f->search, fn ($q, $s) => $q->where('p.name', 'ilike', "%{$s}%"))
                    ->selectRaw("s.name store, p.name || ' – ' || v.name product, fs.quality, fs.method, ROUND(fs.wape * 100, 1) wape,
                        (SELECT SUM(predicted) FROM demand_forecasts WHERE forecast_series_id = fs.id AND horizon_step <= 4) next_4_weeks,
                        (SELECT SUM(lower) FROM demand_forecasts WHERE forecast_series_id = fs.id AND horizon_step <= 4) low,
                        (SELECT SUM(upper) FROM demand_forecasts WHERE forecast_series_id = fs.id AND horizon_step <= 4) high,
                        (SELECT COALESCE(SUM(sl.on_hand),0) FROM stock_levels sl JOIN locations lo ON lo.id = sl.location_id WHERE sl.store_id = fs.store_id AND sl.variant_id = fs.level_id AND lo.is_sellable) available,
                        (fs.drivers->>'trend_percent')::numeric trend, fs.history_periods history"),
                'summary' => function (F $f) {
                    $run = DB::table('forecast_runs')->whereIn('store_id', $f->storeIds)->orderByDesc('id')->first();
                    if (! $run) {
                        return [SalesCatalog::card('run', 'Last calculated', 'Never', 'text', null, 'Press “Recalculate” or wait for the weekly run.')];
                    }
                    $q = DB::table('forecast_series')->where('forecast_run_id', $run->id)->selectRaw("COUNT(*) n, SUM(CASE WHEN quality='validated' THEN 1 ELSE 0 END) ok, SUM(CASE WHEN quality='insufficient_history' THEN 1 ELSE 0 END) none")->first();

                    return [SalesCatalog::card('run', 'Last calculated', substr((string) $run->created_at, 0, 16), 'text', null, 'History '.$run->history_start.' to '.$run->history_end),
                        SalesCatalog::card('validated', 'Validated forecasts', (int) $q->ok, 'int', null, "of {$q->n} products"), SalesCatalog::card('none', 'Not enough history', (int) $q->none, 'int')];
                },
                'sort' => ['next_4_weeks', 'desc'],
            ],
        ];
    }

    public const EXCEPTION_EVENTS = [
        'pos.price_override', 'pos.discount', 'pos.line_removed', 'pos.cart_discarded', 'pos.credit_override', 'sales.credit_note', 'sales.order_cancelled',
        'payment.rejected', 'shift.variance_reviewed', 'inventory.adjustment_posted', 'inventory.adjustment_rejected', 'inventory.count_posted', 'inventory.batch_status',
        'price.changed', 'crm.credit_changed', 'crm.customers_merged', 'user.roles_changed', 'user.status_changed', 'user.password_reset', 'role.deleted',
        'setting.changed', 'hr.document_viewed', 'hr.attendance_corrected', 'report.exported', 'purchasing.po_approved', 'purchasing.supplier_paid',
    ];
}
