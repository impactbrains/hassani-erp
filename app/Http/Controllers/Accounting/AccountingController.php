<?php

namespace App\Http\Controllers\Accounting;

use App\Domain\Accounting\FiscalCalendar;
use App\Domain\Accounting\JournalPoster;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Budget;
use App\Models\FiscalPeriod;
use App\Models\JournalEntry;
use App\Support\Audit;
use App\Support\Decimal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The accounts are the system of record: every sale, purchase, payment,
 * stock change and payroll posts here automatically. People add manual
 * journals and day-to-day expenses; posted entries are never edited, only
 * reversed.
 */
class AccountingController extends Controller
{
    public function accounts(Request $request): Response
    {
        $this->allow($request, 'accounting.view');
        $balances = DB::table('journal_lines as jl')->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')->where('je.status', 'posted')
            ->groupBy('jl.account_id')->selectRaw('jl.account_id, SUM(jl.debit) AS debit, SUM(jl.credit) AS credit')->get()->keyBy('account_id');
        $accounts = Account::query()->orderBy('code')->get()->map(function (Account $a) use ($balances) {
            $b = $balances[$a->id] ?? null;
            $net = $b ? Decimal::sub($b->debit, $b->credit) : '0';

            return $a->toArray() + ['balance' => Decimal::money(in_array($a->type, ['asset', 'expense'], true) ? $net : Decimal::neg($net))];
        });

        return Inertia::render('accounting/Accounts', ['accounts' => $accounts, 'canManage' => $request->user()->hasPermission('accounting.accounts.manage')]);
    }

    public function account(Request $request, Account $account): Response
    {
        $this->allow($request, 'accounting.view');
        $today = $this->currentStore($request)->businessDate();
        $from = $request->query('from', substr($today, 0, 8).'01');
        $to = $request->query('to', $today);
        $sign = in_array($account->type, ['asset', 'expense'], true) ? 1 : -1;
        $opening = DB::table('journal_lines as jl')->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')->where('je.status', 'posted')->where('jl.account_id', $account->id)->where('jl.entry_date', '<', $from)->selectRaw('COALESCE(SUM(jl.debit - jl.credit),0) AS b')->value('b');
        $lines = DB::table('journal_lines as jl')->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')->leftJoin('contacts as c', 'c.id', '=', 'jl.contact_id')->leftJoin('stores as s', 's.id', '=', 'jl.store_id')
            ->where('je.status', 'posted')->where('jl.account_id', $account->id)->whereBetween('jl.entry_date', [$from, $to])->orderBy('jl.entry_date')->orderBy('jl.id')
            ->limit(2000)->get(['jl.entry_date', 'je.id as entry_id', 'je.number', 'je.description', 'jl.memo', 'jl.debit', 'jl.credit', 'c.name as party', 's.name as store']);
        $running = Decimal::mul($opening, (string) $sign);
        $rows = $lines->map(function ($l) use (&$running, $sign) {
            $running = Decimal::add($running, Decimal::mul(Decimal::sub($l->debit, $l->credit), (string) $sign));

            return (array) $l + ['balance' => Decimal::money($running)];
        });

        return Inertia::render('accounting/AccountShow', ['account' => $account, 'opening' => Decimal::money(Decimal::mul($opening, (string) $sign)), 'lines' => $rows, 'closing' => Decimal::money($running), 'filters' => ['from' => $from, 'to' => $to]]);
    }

    public function saveAccount(Request $request, ?Account $account = null): RedirectResponse
    {
        $this->allow($request, 'accounting.accounts.manage');
        $data = $request->validate(['code' => ['required', 'string', 'max:20'], 'name' => ['required', 'string', 'max:120'], 'name_ur' => ['nullable', 'string', 'max:120'], 'type' => ['required', 'in:asset,liability,equity,income,expense'], 'subtype' => ['nullable', 'string', 'max:40'], 'parent_id' => ['nullable', 'integer'], 'is_active' => ['boolean']]);
        if ($account?->system_key && ($data['type'] !== $account->type)) {
            abort(422, 'The type of a system account cannot be changed.');
        }
        $account ??= new Account(['is_postable' => true]);
        $account->fill($data)->save();
        Audit::record('accounting.account_saved', "Account {$account->code} {$account->name} saved", $account);

        return back()->with('success', 'Account saved.');
    }

    public function journals(Request $request): Response
    {
        $this->allow($request, 'accounting.view');
        $q = trim((string) $request->query('q'));

        return Inertia::render('accounting/Journals', [
            'journals' => JournalEntry::query()->where('status', 'posted')->with('creator:id,name')->withSum('lines', 'debit')
                ->when($request->query('kind'), fn ($w, $k) => $w->where('kind', $k))
                ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('number', 'ilike', "%{$q}%")->orWhere('description', 'ilike', "%{$q}%")))
                ->when($request->query('from'), fn ($w, $d) => $w->where('entry_date', '>=', $d))->when($request->query('to'), fn ($w, $d) => $w->where('entry_date', '<=', $d))
                ->latest('id')->paginate($this->perPage($request, 50))->withQueryString(),
            'filters' => $request->only(['q', 'kind', 'from', 'to']),
        ]);
    }

    public function journal(Request $request, JournalEntry $journal): Response
    {
        $this->allow($request, 'accounting.view');

        return Inertia::render('accounting/JournalShow', [
            'journal' => $journal->load('lines.account:id,code,name', 'lines.contact:id,name', 'creator:id,name', 'reversalOf:id,number', 'reversal:id,number,reversal_of_id', 'store:id,name'),
            'source' => $journal->source_type ? ['type' => class_basename($journal->source_type), 'id' => $journal->source_id] : null,
            'canReverse' => $request->user()->hasPermission('accounting.journals.post') && ! $journal->reversal_of_id,
        ]);
    }

    public function createJournal(Request $request): Response
    {
        $this->allow($request, 'accounting.journals.manage');

        return Inertia::render('accounting/JournalForm', ['mode' => $request->query('mode', 'journal')]);
    }

    public function storeJournal(Request $request, JournalPoster $journals): RedirectResponse
    {
        $this->allow($request, 'accounting.journals.post');
        $data = $request->validate(['entry_date' => ['required', 'date'], 'description' => ['required', 'string', 'max:255'], 'store_id' => ['nullable', 'integer', 'in:'.implode(',', $request->user()->accessibleStoreIds())],
            'lines' => ['required', 'array', 'min:2', 'max:100'], 'lines.*.account_id' => ['required', 'integer'], 'lines.*.debit' => ['nullable', 'numeric', 'min:0'], 'lines.*.credit' => ['nullable', 'numeric', 'min:0'], 'lines.*.contact_id' => ['nullable', 'integer'], 'lines.*.memo' => ['nullable', 'string', 'max:200']]);
        $postable = Account::query()->where('is_postable', true)->where('is_active', true)->pluck('id')->all();
        $lines = [];
        foreach ($data['lines'] as $line) {
            abort_unless(in_array((int) $line['account_id'], $postable, true), 422, 'Choose postable, active accounts.');
            $lines[] = ['account' => (int) $line['account_id'], 'debit' => (string) ($line['debit'] ?? '0'), 'credit' => (string) ($line['credit'] ?? '0'), 'contact_id' => $line['contact_id'] ?? null, 'memo' => $line['memo'] ?? null];
        }
        $entry = $journals->post('manual', null, $data['entry_date'], $data['description'], $lines, $data['store_id'] ?? null, 'manual');
        Audit::record('accounting.journal_posted', "Manual journal {$entry?->number}: {$data['description']}", $entry);

        return redirect()->route('accounting.journals.show', $entry)->with('success', "Journal {$entry?->number} posted.");
    }

    /** Day-to-day expense paid from cash, bank or a wallet (rent, electricity, salaries outside payroll ...). */
    public function storeExpense(Request $request, JournalPoster $journals): RedirectResponse
    {
        $this->allow($request, 'accounting.journals.manage');
        $data = $request->validate(['entry_date' => ['required', 'date'], 'expense_account_id' => ['required', 'integer'], 'paid_from_account_id' => ['required', 'integer'], 'amount' => ['required', 'numeric', 'gt:0'], 'description' => ['required', 'string', 'max:200'], 'contact_id' => ['nullable', 'integer']]);
        $expense = Account::query()->whereIn('type', ['expense', 'asset', 'equity', 'liability'])->where('is_postable', true)->findOrFail($data['expense_account_id']);
        $from = Account::query()->whereIn('subtype', ['cash', 'bank', 'wallet'])->findOrFail($data['paid_from_account_id']);
        $store = $this->currentStore($request);
        $entry = $journals->post('manual', null, $data['entry_date'], 'Expense: '.$data['description'], [
            ['account' => $expense->id, 'debit' => (string) $data['amount'], 'contact_id' => $data['contact_id'] ?? null, 'memo' => $data['description']],
            ['account' => $from->id, 'credit' => (string) $data['amount'], 'memo' => $data['description']],
        ], $store->id, 'expense');
        Audit::record('accounting.expense_recorded', "Expense Rs {$data['amount']} {$data['description']}", $entry, storeId: $store->id);

        return back()->with('success', "Expense recorded ({$entry?->number}).");
    }

    public function reverse(Request $request, JournalEntry $journal, JournalPoster $journals): RedirectResponse
    {
        $this->allow($request, 'accounting.journals.post');
        $data = $request->validate(['date' => ['required', 'date'], 'reason' => ['required', 'string', 'min:5', 'max:200']]);
        abort_if($journal->kind === 'auto', 422, 'Automatic entries are corrected from their document (a return, a reversal of the payment ...), not here.');
        $reversal = $journals->reverse($journal, $data['date'], $data['reason']);
        Audit::record('accounting.journal_reversed', "Reversed {$journal->number}: {$data['reason']}", $reversal);

        return redirect()->route('accounting.journals.show', $reversal)->with('success', 'Reversed.');
    }

    public function periods(Request $request, FiscalCalendar $calendar): Response
    {
        $this->allow($request, 'accounting.view');
        $calendar->periodFor(now()->toDateString());

        return Inertia::render('accounting/Periods', ['periods' => FiscalPeriod::query()->with('fiscalYear:id,name')->orderByDesc('starts_on')->limit(36)->get(), 'canManage' => $request->user()->hasPermission('accounting.periods.manage')]);
    }

    public function togglePeriod(Request $request, FiscalPeriod $period): RedirectResponse
    {
        $this->allow($request, 'accounting.periods.manage');
        $closing = $period->status === 'open';
        $request->validate(['reason' => [$closing ? 'nullable' : 'required', 'string', 'max:300']]);
        $period->update(['status' => $closing ? 'closed' : 'open', 'closed_by' => $closing ? $request->user()->id : null, 'closed_at' => $closing ? now() : null]);
        Audit::record($closing ? 'accounting.period_closed' : 'accounting.period_reopened', ($closing ? 'Closed ' : 'Reopened ').$period->name.($closing ? '' : ': '.$request->input('reason')), $period);

        return back()->with('success', $closing ? "{$period->name} closed. Nothing can be posted into it now." : "{$period->name} reopened.");
    }

    public function budgets(Request $request): Response
    {
        $this->allow($request, 'accounting.view');
        $year = (int) $request->query('year', now()->year);

        return Inertia::render('accounting/Budgets', [
            'year' => $year,
            'accounts' => Account::query()->whereIn('type', ['expense', 'income'])->where('is_postable', true)->orderBy('code')->get(['id', 'code', 'name', 'type']),
            'budgets' => Budget::query()->whereBetween('month', ["{$year}-01-01", "{$year}-12-31"])->whereNull('store_id')->get(['account_id', 'month', 'amount']),
            'canManage' => $request->user()->hasPermission('accounting.budgets.manage'),
        ]);
    }

    public function saveBudgets(Request $request): RedirectResponse
    {
        $this->allow($request, 'accounting.budgets.manage');
        $data = $request->validate(['rows' => ['required', 'array', 'max:2000'], 'rows.*.account_id' => ['required', 'integer'], 'rows.*.month' => ['required', 'date'], 'rows.*.amount' => ['nullable', 'numeric', 'min:0']]);
        foreach ($data['rows'] as $row) {
            $month = substr($row['month'], 0, 7).'-01';
            if ($row['amount'] === null || $row['amount'] === '') {
                Budget::query()->where('account_id', $row['account_id'])->where('month', $month)->whereNull('store_id')->delete();
            } else {
                Budget::query()->updateOrCreate(['account_id' => $row['account_id'], 'month' => $month, 'store_id' => null], ['amount' => Decimal::money((string) $row['amount']), 'updated_by' => $request->user()->id]);
            }
        }

        return back()->with('success', 'Budget saved.');
    }
}
