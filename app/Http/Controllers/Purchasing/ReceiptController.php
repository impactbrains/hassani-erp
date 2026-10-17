<?php

namespace App\Http\Controllers\Purchasing;

use App\Domain\Purchasing\ReceivingService;
use App\Http\Controllers\Controller;
use App\Models\GoodsReceipt;
use App\Models\PurchaseOrder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Goods received notes (GRN): what actually arrived, damage, batches/expiry and freight. */
class ReceiptController extends Controller
{
    public function index(Request $request): Response
    {
        $stores = $request->user()->storeIdsFor('purchasing.receive');

        return Inertia::render('purchasing/Receipts', [
            'receipts' => GoodsReceipt::query()->whereIn('store_id', $stores)->with('supplier:id,name', 'purchaseOrder:id,number', 'receiver:id,name')
                ->latest('id')->paginate($this->perPage($request))->withQueryString(),
            'showCost' => $request->user()->hasPermission('catalog.costs.view'),
        ]);
    }

    public function create(Request $request): Response
    {
        $store = $this->currentStore($request);
        $this->allow($request, 'purchasing.receive', $store);
        $order = $request->integer('purchase_order_id') ? PurchaseOrder::query()->where('store_id', $store->id)->with('lines.variant:id,product_id,sku,name', 'lines.variant.product:id,name', 'lines.variantUnit.unit:id,code', 'supplier:id,name')->find($request->integer('purchase_order_id')) : null;

        return Inertia::render('purchasing/ReceiptForm', ['order' => $order, 'showCost' => $request->user()->hasPermission('catalog.costs.view')]);
    }

    public function store(Request $request, ReceivingService $receiving): RedirectResponse
    {
        $store = $this->currentStore($request);
        $this->allow($request, 'purchasing.receive', $store);
        $data = $request->validate([
            'supplier_id' => ['required', 'integer'], 'purchase_order_id' => ['nullable', 'integer'], 'location_id' => ['nullable', 'integer'], 'supplier_reference' => ['nullable', 'string', 'max:80'], 'notes' => ['nullable', 'string', 'max:1000'], 'is_blind' => ['boolean'],
            'lines' => ['required', 'array', 'min:1', 'max:500'], 'lines.*.variant_id' => ['required', 'integer'], 'lines.*.variant_unit_id' => ['required', 'integer'], 'lines.*.purchase_order_line_id' => ['nullable', 'integer'],
            'lines.*.quantity_received' => ['required', 'numeric', 'min:0'], 'lines.*.quantity_damaged' => ['nullable', 'numeric', 'min:0'], 'lines.*.unit_price' => ['required', 'numeric', 'min:0'],
            'lines.*.batch_no' => ['nullable', 'string', 'max:60'], 'lines.*.expiry_date' => ['nullable', 'date'], 'lines.*.quality_status' => ['nullable', 'in:accepted,quarantined'], 'lines.*.discrepancy_note' => ['nullable', 'string', 'max:300'],
            'landed_costs' => ['array'], 'landed_costs.*.type' => ['required', 'in:freight,loading,customs,other'], 'landed_costs.*.amount' => ['required', 'numeric', 'gt:0'], 'landed_costs.*.description' => ['nullable', 'string', 'max:200'], 'landed_costs.*.allocation_method' => ['nullable', 'in:value,quantity'],
        ]);
        $receipt = $receiving->receive($request->user(), $store, $data);

        return redirect()->route('purchasing.receipts.show', $receipt)->with('success', "Received {$receipt->number}. Stock is updated.");
    }

    public function show(Request $request, GoodsReceipt $receipt): Response
    {
        $this->allow($request, 'purchasing.receive', $receipt->store_id);

        return Inertia::render('purchasing/ReceiptShow', [
            'receipt' => $receipt->load('lines.variant:id,product_id,sku,name', 'lines.variant.product:id,name', 'lines.variantUnit.unit:id,code', 'lines.batch:id,batch_no,expiry_date', 'landedCosts', 'supplier:id,name', 'purchaseOrder:id,number', 'location:id,name', 'receiver:id,name', 'store:id,name'),
            'showCost' => $request->user()->hasPermission('catalog.costs.view'),
        ]);
    }
}
