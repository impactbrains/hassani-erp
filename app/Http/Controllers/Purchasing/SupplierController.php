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
use App\Support\Pager;
use Illuminate\Http\JsonResponse;
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
        Pager::remember($request, 'purchasing.suppliers');

        return Inertia::render('purchasing/Suppliers', [
            'suppliers' => $this->listQuery($request)->with('supplierProfile')->paginate($this->perPage($request, 30))->withQueryString(),
            'filters' => $request->only(['q']),
        ]);
    }

    /** The suppliers list as filtered on screen. The next / previous arrows on a supplier walk through this same list. */
    private function listQuery(Request $request): \Illuminate\Database\Eloquent\Builder
    {
        $balances = DB::table('journal_lines as jl')->join('accounts as a', 'a.id', '=', 'jl.account_id')->where('a.system_key', 'accounts_payable')->whereNotNull('jl.contact_id')
            ->groupBy('jl.contact_id')->selectRaw('jl.contact_id, SUM(jl.credit - jl.debit) AS payable');

        return Contact::query()->ofType('supplier')->search($request->string('q')->toString() ?: null)
            ->leftJoinSub($balances, 'b', 'b.contact_id', '=', 'contacts.id')->select('contacts.*', 'b.payable')
            ->orderBy('name')->orderBy('contacts.id');
    }

    public function show(Request $request, Contact $supplier): Response
    {
        $this->allow($request, 'purchasing.suppliers.view');
        abort_unless($supplier->type === 'supplier', 404);

        return Inertia::render('purchasing/SupplierShow', [
            'pager' => Pager::for($request, 'purchasing.suppliers', $supplier->id, fn (Request $r) => $this->listQuery($r), fn (int $id) => route('purchasing.suppliers.show', $id)),
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

    /**
     * Adds a supplier from the search box on Purchase stock / Goods received and returns it as JSON.
     * A supplier with the same phone or name is offered first, unless the person confirms it is a new one.
     */
    public function quickStore(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('purchasing.suppliers.manage') || $request->user()->hasPermission('inventory.purchase_stock', $this->currentStore($request)->id), 403);
        $data = $request->validate(['name' => ['required', 'string', 'max:200'], 'phone' => ['nullable', 'string', 'max:30'], 'area' => ['nullable', 'string', 'max:120'], 'confirm_not_duplicate' => ['boolean']]);

        if (! $request->boolean('confirm_not_duplicate')) {
            $phone = Contact::normalizePhone((string) ($data['phone'] ?? ''));
            $matches = Contact::query()->ofType('supplier')->where(function ($q) use ($data, $phone) {
                $q->whereRaw('lower(name) = ?', [mb_strtolower(trim($data['name']))]);
                if ($phone !== null && strlen($phone) >= 7) {
                    $q->orWhere('phone_normalized', $phone);
                }
            })->limit(5)->get(['id', 'code', 'name', 'name_ur', 'phone', 'area']);
            if ($matches->isNotEmpty()) {
                return response()->json(['message' => 'This supplier may already exist.', 'code' => 'possible_duplicate', 'details' => ['matches' => $matches]], 422);
            }
        }

        $supplier = DB::transaction(function () use ($data, $request) {
            $supplier = new Contact(['type' => 'supplier', 'created_by' => $request->user()->id]);
            $supplier->fill(['name' => trim($data['name']), 'phone' => $data['phone'] ?? null, 'area' => $data['area'] ?? null, 'is_active' => true]);
            $supplier->code = Sequences::next('supplier');
            $supplier->save();
            SupplierProfile::query()->updateOrCreate(['contact_id' => $supplier->id], ['lead_time_days' => 3, 'payment_terms_days' => 0, 'min_order_value' => 0]);
            Audit::record('purchasing.supplier_created', "Supplier {$supplier->name} added while buying stock", $supplier);

            return $supplier;
        });

        return response()->json($supplier->only(['id', 'code', 'name', 'name_ur', 'phone', 'area']), 201);
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
