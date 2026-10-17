<?php

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\GoodsReceipt;
use App\Models\PurchaseOrder;
use App\Models\SupplierBill;
use App\Models\SupplierProfile;
use App\Support\Audit;
use App\Support\Sequences;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class SupplierController extends Controller
{
    public function index(Request $request): Response
    {
        $this->allow($request, 'purchasing.suppliers.view');
        $balances = DB::table('journal_lines as jl')->join('accounts as a', 'a.id', '=', 'jl.account_id')->where('a.system_key', 'accounts_payable')->whereNotNull('jl.contact_id')
            ->groupBy('jl.contact_id')->selectRaw('jl.contact_id, SUM(jl.credit - jl.debit) AS payable');

        return Inertia::render('purchasing/Suppliers', [
            'suppliers' => Contact::query()->ofType('supplier')->search($request->string('q')->toString() ?: null)->with('supplierProfile')
                ->leftJoinSub($balances, 'b', 'b.contact_id', '=', 'contacts.id')->select('contacts.*', 'b.payable')
                ->orderBy('name')->paginate($this->perPage($request, 30))->withQueryString(),
            'filters' => $request->only(['q']),
        ]);
    }

    public function show(Request $request, Contact $supplier): Response
    {
        $this->allow($request, 'purchasing.suppliers.view');
        abort_unless($supplier->type === 'supplier', 404);

        return Inertia::render('purchasing/SupplierShow', [
            'supplier' => $supplier->load('supplierProfile'),
            'orders' => PurchaseOrder::query()->where('supplier_id', $supplier->id)->latest('id')->limit(20)->get(['id', 'number', 'status', 'order_date', 'total']),
            'receipts' => GoodsReceipt::query()->where('supplier_id', $supplier->id)->latest('id')->limit(20)->get(['id', 'number', 'status', 'business_date', 'goods_value']),
            'bills' => SupplierBill::query()->where('supplier_id', $supplier->id)->latest('id')->limit(20)->get(['id', 'number', 'supplier_invoice_no', 'bill_date', 'due_date', 'total', 'amount_paid', 'payment_status']),
            'payable' => DB::table('journal_lines as jl')->join('accounts as a', 'a.id', '=', 'jl.account_id')->where('a.system_key', 'accounts_payable')->where('jl.contact_id', $supplier->id)->selectRaw('COALESCE(SUM(jl.credit - jl.debit),0) AS b')->value('b'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $supplier = $this->save($request, null);

        return redirect()->route('purchasing.suppliers.show', $supplier)->with('success', 'Supplier saved.');
    }

    public function update(Request $request, Contact $supplier): RedirectResponse
    {
        abort_unless($supplier->type === 'supplier', 404);
        $this->save($request, $supplier);

        return back()->with('success', 'Supplier saved.');
    }

    private function save(Request $request, ?Contact $supplier): Contact
    {
        $this->allow($request, 'purchasing.suppliers.manage');
        $data = $request->validate(['name' => ['required', 'string', 'max:200'], 'name_ur' => ['nullable', 'string', 'max:200'], 'phone' => ['nullable', 'string', 'max:30'], 'alt_phone' => ['nullable', 'string', 'max:30'], 'email' => ['nullable', 'email'], 'address' => ['nullable', 'string', 'max:500'], 'city' => ['nullable', 'string', 'max:80'], 'tax_number' => ['nullable', 'string', 'max:40'], 'is_active' => ['boolean'],
            'lead_time_days' => ['nullable', 'integer', 'min:0', 'max:365'], 'payment_terms_days' => ['nullable', 'integer', 'min:0', 'max:365'], 'min_order_value' => ['nullable', 'numeric', 'min:0'], 'bank_details' => ['nullable', 'string', 'max:500']]);

        return DB::transaction(function () use ($data, $supplier, $request) {
            $isNew = $supplier === null;
            $supplier ??= new Contact(['type' => 'supplier', 'created_by' => $request->user()->id]);
            $supplier->fill(collect($data)->only(['name', 'name_ur', 'phone', 'alt_phone', 'email', 'address', 'city', 'tax_number', 'is_active'])->all());
            if ($isNew) {
                $supplier->code = Sequences::next('supplier');
            }
            $supplier->save();
            $defaults = ['lead_time_days' => 3, 'payment_terms_days' => 0, 'min_order_value' => 0];
            SupplierProfile::query()->updateOrCreate(['contact_id' => $supplier->id], collect($data)->only(['lead_time_days', 'payment_terms_days', 'min_order_value', 'bank_details'])->map(fn ($v, $k) => $v ?? ($defaults[$k] ?? null))->all());
            Audit::record($isNew ? 'purchasing.supplier_created' : 'purchasing.supplier_updated', "Supplier {$supplier->name} saved", $supplier);

            return $supplier;
        });
    }
}
