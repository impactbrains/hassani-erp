<?php

namespace App\Http\Controllers\Purchasing;

use App\Domain\Purchasing\PayablesService;
use App\Http\Controllers\Controller;
use App\Models\GoodsReceiptLine;
use App\Models\Shift;
use App\Models\SupplierBill;
use App\Models\SupplierReturn;
use App\Support\Pager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/** Supplier bills (matched to goods received), payments to suppliers and returns to suppliers. */
class PayablesController extends Controller
{
    public function bills(Request $request): Response
    {
        Pager::remember($request, 'purchasing.bills');
        $stores = $request->user()->storeIdsFor('purchasing.bills.manage');

        return Inertia::render('purchasing/Bills', [
            'bills' => $this->billsQuery($request)->with('supplier:id,name')->paginate($this->perPage($request))->withQueryString(),
            'returns' => SupplierReturn::query()->whereIn('store_id', $stores)->with('supplier:id,name', 'reasonCode:id,label')->latest('id')->limit(20)->get(),
            'filters' => $request->only(['payment_status']),
        ]);
    }

    /** The bills list as filtered on screen. The next / previous arrows on a bill walk through this same list. */
    private function billsQuery(Request $request): \Illuminate\Database\Eloquent\Builder
    {
        return SupplierBill::query()->whereIn('store_id', $request->user()->storeIdsFor('purchasing.bills.manage'))
            ->when($request->query('payment_status'), fn ($q, $s) => $q->where('payment_status', $s))->latest('id');
    }

    public function createBill(Request $request): Response
    {
        $this->allow($request, 'purchasing.bills.manage', $this->currentStore($request));
        $supplierId = $request->integer('supplier_id');

        return Inertia::render('purchasing/BillForm', [
            'supplierId' => $supplierId ?: null,
            'supplier' => $supplierId ? \App\Models\Contact::query()->ofType('supplier')->find($supplierId, ['id', 'name', 'code', 'phone']) : null,
            'unbilled' => $supplierId ? GoodsReceiptLine::query()->whereHas('receipt', fn ($q) => $q->where('supplier_id', $supplierId)->where('status', 'posted'))
                ->whereRaw('quantity_received * unit_factor > base_quantity_billed')->with('variant:id,product_id,sku,name', 'variant.product:id,name', 'receipt:id,number,business_date', 'variantUnit.unit:id,code')->get() : [],
        ]);
    }

    public function storeBill(Request $request, PayablesService $payables): RedirectResponse
    {
        $data = $request->validate(['supplier_id' => ['required', 'integer'], 'supplier_invoice_no' => ['nullable', 'string', 'max:60'], 'bill_date' => ['required', 'date'], 'due_date' => ['nullable', 'date', 'after_or_equal:bill_date'], 'notes' => ['nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:1'], 'lines.*.goods_receipt_line_id' => ['nullable', 'integer'], 'lines.*.account_id' => ['nullable', 'required_without:lines.*.goods_receipt_line_id', 'integer'], 'lines.*.description' => ['nullable', 'string', 'max:200'], 'lines.*.quantity' => ['required', 'numeric', 'gt:0'], 'lines.*.unit_price' => ['required', 'numeric', 'min:0'], 'lines.*.tax_amount' => ['nullable', 'numeric', 'min:0']]);
        $bill = $payables->postBill($request->user(), $this->currentStore($request), $data);

        return redirect()->route('purchasing.bills.show', $bill)->with('success', "Bill {$bill->number} posted.");
    }

    public function showBill(Request $request, SupplierBill $bill): Response
    {
        $this->allow($request, 'purchasing.bills.manage', $bill->store_id);

        return Inertia::render('purchasing/BillShow', [
            'pager' => Pager::for($request, 'purchasing.bills', $bill->id, fn (Request $r) => $this->billsQuery($r), fn (int $id) => route('purchasing.bills.show', $id)),
            'bill' => $bill->load('lines', 'supplier:id,name', 'store:id,name'),
            'openShift' => Shift::query()->where('store_id', $bill->store_id)->where('status', 'open')->where('opened_by', $request->user()->id)->value('id'),
        ]);
    }

    public function pay(Request $request, PayablesService $payables): RedirectResponse
    {
        $data = $request->validate(['supplier_id' => ['required', 'integer'], 'payment_method_id' => ['required', 'integer'], 'amount' => ['required', 'numeric', 'gt:0'], 'reference' => ['nullable', 'string', 'max:80'], 'shift_id' => ['nullable', 'integer'], 'bill_ids' => ['array'], 'bill_ids.*' => ['integer'], 'idempotency_key' => ['required', 'string', 'max:64'], 'notes' => ['nullable', 'string', 'max:300']]);
        $payment = $payables->paySupplier($request->user(), $this->currentStore($request), $data);

        return back()->with('success', "Paid Rs {$payment->amount} ({$payment->number}).");
    }

    public function createReturn(Request $request): Response
    {
        $store = $this->currentStore($request);
        $this->allow($request, 'purchasing.returns.manage', $store);
        $supplierId = $request->integer('supplier_id');

        return Inertia::render('purchasing/ReturnForm', [
            'supplierId' => $supplierId ?: null,
            'supplier' => $supplierId ? \App\Models\Contact::query()->ofType('supplier')->find($supplierId, ['id', 'name', 'code', 'phone']) : null,
            'lines' => $supplierId ? GoodsReceiptLine::query()->whereHas('receipt', fn ($q) => $q->where('supplier_id', $supplierId)->where('store_id', $store->id)->where('status', 'posted')->where('business_date', '>=', now()->subDays(180)->toDateString()))
                ->with('variant:id,product_id,sku,name', 'variant.product:id,name', 'receipt:id,number,business_date', 'batch:id,batch_no')->latest('id')->limit(200)->get() : [],
            'reasons' => DB::table('reason_codes')->where('company_id', $store->company_id)->where('type', 'supplier_return')->get(['id', 'label']),
        ]);
    }

    public function storeReturn(Request $request, PayablesService $payables): RedirectResponse
    {
        $data = $request->validate(['supplier_id' => ['required', 'integer'], 'goods_receipt_id' => ['nullable', 'integer'], 'reason_code_id' => ['nullable', 'integer'], 'notes' => ['nullable', 'string', 'max:500'], 'lines' => ['required', 'array', 'min:1'], 'lines.*.goods_receipt_line_id' => ['required', 'integer'], 'lines.*.location_id' => ['required', 'integer'], 'lines.*.base_quantity' => ['required', 'numeric', 'gt:0']]);
        $return = $payables->returnToSupplier($request->user(), $this->currentStore($request), $data);

        return redirect()->route('purchasing.bills')->with('success', "Return {$return->number} posted.");
    }
}
