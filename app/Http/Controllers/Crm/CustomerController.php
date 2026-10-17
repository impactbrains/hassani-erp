<?php

namespace App\Http\Controllers\Crm;

use App\Domain\Crm\CustomerService;
use App\Domain\Crm\KhataService;
use App\Domain\Sales\CreditControl;
use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\CrmCase;
use App\Models\CrmFollowUp;
use App\Models\CrmInteraction;
use App\Models\PaymentPromise;
use App\Models\PriceBook;
use App\Models\SalesInvoice;
use App\Models\SalesOrder;
use App\Models\Tag;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/** Customers, their Khata (statement, aging, promises) and relationship history. */
class CustomerController extends Controller
{
    public function index(Request $request): Response
    {
        $this->allow($request, 'crm.customers.view');
        $user = $request->user();
        $balances = DB::table('journal_lines as jl')->join('accounts as a', 'a.id', '=', 'jl.account_id')->where('a.system_key', 'accounts_receivable')->whereNotNull('jl.contact_id')
            ->groupBy('jl.contact_id')->selectRaw('jl.contact_id, SUM(jl.debit - jl.credit) AS khata_balance');
        $customers = Contact::query()->customers()->search($request->string('q')->toString() ?: null)->with('customerProfile:id,contact_id,category,credit_limit,credit_status', 'tags:id,name,color')
            ->leftJoinSub($balances, 'b', 'b.contact_id', '=', 'contacts.id')->select('contacts.*', 'b.khata_balance')
            ->when($request->query('category'), fn ($q, $c) => $q->whereHas('customerProfile', fn ($p) => $p->where('category', $c)))
            ->when($request->query('with_balance'), fn ($q) => $q->where('b.khata_balance', '>', 0))
            ->when($request->query('area'), fn ($q, $a) => $q->where('area', 'ilike', $a))
            ->when($request->query('sort') === 'balance', fn ($q) => $q->orderByDesc('b.khata_balance'), fn ($q) => $q->orderBy('name'))
            ->paginate($this->perPage($request, 30))->withQueryString();
        if (! $user->hasPermission('crm.customers.contact_details')) {
            $customers->getCollection()->each->makeHidden(['phone', 'alt_phone', 'email', 'address', 'phone_normalized']);
        }
        if (! $user->hasPermission('crm.khata.view')) {
            $customers->getCollection()->each->makeHidden(['khata_balance']);
        }

        return Inertia::render('crm/Customers', ['customers' => $customers, 'filters' => $request->only(['q', 'category', 'with_balance', 'area', 'sort']), 'canExport' => $user->hasPermission('crm.customers.export')]);
    }

    public function create(Request $request): Response
    {
        $this->allow($request, 'crm.customers.manage');

        return Inertia::render('crm/CustomerForm', ['customer' => null] + $this->formData());
    }

    public function store(Request $request, CustomerService $customers): RedirectResponse
    {
        $this->allow($request, 'crm.customers.manage');
        $customer = $customers->save($request->user(), $this->validated($request));

        return redirect()->route('crm.customers.show', $customer)->with('success', "{$customer->name} added ({$customer->code}).");
    }

    public function show(Request $request, Contact $customer, KhataService $khata, CreditControl $credit): Response
    {
        $this->allow($request, 'crm.customers.view');
        abort_unless($customer->type === 'customer', 404);
        $user = $request->user();
        $today = $this->currentStore($request)->businessDate();
        $from = $request->query('from', \Carbon\CarbonImmutable::parse($today)->subMonths(3)->startOfMonth()->toDateString());
        $to = $request->query('to', $today);
        $canKhata = $user->hasPermission('crm.khata.view');
        $customer->load('customerProfile.priceBook:id,name', 'customerProfile.salesAgent:id,name', 'tags:id,name,color', 'mergedInto:id,name');
        if (! $user->hasPermission('crm.customers.contact_details')) {
            $customer->makeHidden(['phone', 'alt_phone', 'email', 'address', 'phone_normalized', 'delivery_instructions']);
        }

        return Inertia::render('crm/CustomerShow', [
            'customer' => $customer,
            'khata' => $canKhata ? ['balance' => $credit->balance($customer->id), 'statement' => $khata->statement($customer, $from, $to), 'aging' => $khata->aging($customer->id, $today)] : null,
            'promises' => $canKhata ? PaymentPromise::query()->where('contact_id', $customer->id)->with('recorder:id,name')->latest('promised_date')->limit(20)->get() : [],
            'interactions' => CrmInteraction::query()->where('contact_id', $customer->id)->when(! $user->hasPermission('crm.notes.sensitive'), fn ($q) => $q->where('is_sensitive', false))->with('user:id,name')->latest('occurred_at')->limit(50)->get(),
            'followUps' => CrmFollowUp::query()->where('contact_id', $customer->id)->with('assignee:id,name')->orderByRaw("status = 'done'")->orderBy('due_at')->limit(30)->get(),
            'cases' => CrmCase::query()->where('contact_id', $customer->id)->with('owner:id,name')->latest('id')->limit(20)->get(),
            'invoices' => SalesInvoice::query()->where('customer_id', $customer->id)->where('status', 'posted')->latest('id')->limit(20)->get(['id', 'number', 'business_date', 'total', 'amount_paid', 'amount_credited', 'payment_status', 'due_date']),
            'orders' => SalesOrder::query()->where('customer_id', $customer->id)->latest('id')->limit(10)->get(['id', 'number', 'status', 'total', 'business_date']),
            'stats' => DB::table('sales_invoices')->where('customer_id', $customer->id)->where('status', 'posted')->selectRaw('COUNT(*) AS orders, COALESCE(SUM(total - amount_credited),0) AS lifetime, MIN(business_date) AS first, MAX(business_date) AS last')->first(),
            'filters' => ['from' => $from, 'to' => $to],
            'can' => ['manage' => $user->hasPermission('crm.customers.manage'), 'credit' => $user->hasPermission('crm.credit.manage'), 'merge' => $user->hasPermission('crm.customers.merge'), 'collect' => $user->hasPermission('sales.payments.receive'), 'order' => $user->hasPermission('sales.orders.manage')],
        ]);
    }

    public function edit(Request $request, Contact $customer): Response
    {
        $this->allow($request, 'crm.customers.manage');

        return Inertia::render('crm/CustomerForm', ['customer' => $customer->load('customerProfile', 'tags:id')] + $this->formData());
    }

    public function update(Request $request, Contact $customer, CustomerService $customers): RedirectResponse
    {
        $this->allow($request, 'crm.customers.manage');
        $customers->save($request->user(), $this->validated($request), $customer);

        return redirect()->route('crm.customers.show', $customer)->with('success', 'Saved.');
    }

    public function merge(Request $request, Contact $customer, CustomerService $customers): RedirectResponse
    {
        $this->allow($request, 'crm.customers.merge');
        $duplicate = Contact::query()->customers()->findOrFail($request->validate(['duplicate_id' => ['required', 'integer', 'different:'.$customer->id]])['duplicate_id']);
        $customers->merge($request->user(), $customer, $duplicate);

        return back()->with('success', "{$duplicate->name} was merged into {$customer->name}.");
    }

    /** Notes, calls and visits; follow-ups; complaints (cases); payment promises. */
    public function activity(Request $request, Contact $customer): RedirectResponse
    {
        $user = $request->user();
        $kind = $request->validate(['kind' => ['required', 'in:interaction,follow_up,case,promise']])['kind'];
        match ($kind) {
            'interaction' => (function () use ($request, $customer, $user) {
                $this->allow($request, 'crm.customers.view');
                $data = $request->validate(['channel' => ['required', 'in:call,visit,whatsapp,sms,note,email'], 'summary' => ['required', 'string', 'max:2000'], 'is_sensitive' => ['boolean']]);
                CrmInteraction::create($data + ['contact_id' => $customer->id, 'user_id' => $user->id, 'occurred_at' => now()]);
            })(),
            'follow_up' => (function () use ($request, $customer, $user) {
                $this->allow($request, 'crm.followups.manage');
                $data = $request->validate(['title' => ['required', 'string', 'max:200'], 'notes' => ['nullable', 'string', 'max:1000'], 'due_at' => ['required', 'date'], 'assigned_to' => ['nullable', 'integer'], 'priority' => ['nullable', 'in:low,normal,high']]);
                CrmFollowUp::create($data + ['contact_id' => $customer->id, 'status' => 'open', 'assigned_to' => $data['assigned_to'] ?? $user->id, 'priority' => $data['priority'] ?? 'normal', 'created_by' => $user->id]);
            })(),
            'case' => (function () use ($request, $customer, $user) {
                $this->allow($request, 'crm.cases.manage');
                $data = $request->validate(['type' => ['required', 'in:complaint,return_request,quality,delivery,billing,other'], 'subject' => ['required', 'string', 'max:200'], 'description' => ['nullable', 'string', 'max:2000'], 'priority' => ['nullable', 'in:low,normal,high,urgent'], 'due_at' => ['nullable', 'date']]);
                CrmCase::create($data + ['contact_id' => $customer->id, 'store_id' => $request->attributes->get('store_id'), 'number' => \App\Support\Sequences::next('crm_case'), 'status' => 'open', 'priority' => $data['priority'] ?? 'normal', 'owner_id' => $user->id, 'created_by' => $user->id]);
            })(),
            'promise' => (function () use ($request, $customer, $user) {
                $this->allow($request, 'crm.khata.view');
                $data = $request->validate(['amount' => ['required', 'numeric', 'gt:0'], 'promised_date' => ['required', 'date', 'after_or_equal:today'], 'note' => ['nullable', 'string', 'max:500']]);
                PaymentPromise::create($data + ['contact_id' => $customer->id, 'status' => 'open', 'recorded_by' => $user->id]);
            })(),
        };
        Audit::record('crm.activity', "Added {$kind} for {$customer->name}", $customer);

        return back()->with('success', 'Saved.');
    }

    public function updateFollowUp(Request $request, CrmFollowUp $followUp): RedirectResponse
    {
        $this->allow($request, 'crm.followups.manage');
        $followUp->update(['status' => 'done', 'completed_at' => now()]);

        return back();
    }

    public function updateCase(Request $request, CrmCase $case): RedirectResponse
    {
        $this->allow($request, 'crm.cases.manage');
        $data = $request->validate(['status' => ['required', 'in:open,in_progress,resolved,closed'], 'resolution' => ['nullable', 'required_if:status,resolved,closed', 'string', 'max:2000'], 'owner_id' => ['nullable', 'integer']]);
        $case->update($data + ['resolved_at' => in_array($data['status'], ['resolved', 'closed'], true) ? now() : null]);

        return back()->with('success', 'Case updated.');
    }

    /** Work list: my open follow-ups and cases, and promises due. */
    public function work(Request $request): Response
    {
        $this->allow($request, 'crm.customers.view');
        $user = $request->user();

        return Inertia::render('crm/Work', [
            'followUps' => CrmFollowUp::query()->where('status', 'open')->when(! $user->hasPermission('crm.followups.manage') || $request->query('mine', '1') === '1', fn ($q) => $q->where('assigned_to', $user->id))->with('contact:id,name,code', 'assignee:id,name')->orderBy('due_at')->limit(100)->get(),
            'cases' => CrmCase::query()->whereIn('status', ['open', 'in_progress'])->with('contact:id,name,code', 'owner:id,name')->orderByRaw("CASE priority WHEN 'urgent' THEN 0 WHEN 'high' THEN 1 ELSE 2 END")->orderBy('due_at')->limit(100)->get(),
            'promises' => $user->hasPermission('crm.khata.view') ? PaymentPromise::query()->where('status', 'open')->where('promised_date', '<=', now()->addDays(3)->toDateString())->with('contact:id,name,code')->orderBy('promised_date')->limit(100)->get() : [],
            'filters' => ['mine' => $request->query('mine', '1')],
        ]);
    }

    /** @return array<string, mixed> */
    private function formData(): array
    {
        return ['priceBooks' => PriceBook::query()->where('is_active', true)->get(['id', 'name']), 'tags' => Tag::query()->orderBy('name')->get(['id', 'name', 'color'])];
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:200'], 'name_ur' => ['nullable', 'string', 'max:200'], 'phone' => ['nullable', 'string', 'max:30'], 'alt_phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:190'], 'address' => ['nullable', 'string', 'max:500'], 'area' => ['nullable', 'string', 'max:120'], 'city' => ['nullable', 'string', 'max:80'],
            'delivery_instructions' => ['nullable', 'string', 'max:500'], 'tax_number' => ['nullable', 'string', 'max:40'], 'preferred_language' => ['nullable', 'in:en,ur'], 'is_active' => ['boolean'],
            'category' => ['nullable', 'in:retail,wholesale,shopkeeper,institution'], 'price_book_id' => ['nullable', 'integer'], 'sales_agent_id' => ['nullable', 'integer'],
            'credit_limit' => ['nullable', 'numeric', 'min:0'], 'payment_terms_days' => ['nullable', 'integer', 'min:0', 'max:365'], 'credit_status' => ['nullable', 'in:active,on_hold,blocked'], 'credit_status_reason' => ['nullable', 'string', 'max:300'],
            'marketing_consent' => ['boolean'], 'consent_channels' => ['nullable', 'array'], 'tag_ids' => ['array'], 'tag_ids.*' => ['integer'], 'confirm_not_duplicate' => ['boolean'],
        ]);
    }
}
