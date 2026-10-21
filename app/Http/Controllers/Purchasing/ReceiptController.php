<?php

namespace App\Http\Controllers\Purchasing;

use App\Domain\Purchasing\ReceivingService;
use App\Http\Controllers\Controller;
use App\Models\GoodsReceipt;
use App\Models\PurchaseOrder;
use App\Models\Store;
use App\Support\Pager;
use App\Support\Settings\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Goods received notes (GRN): what actually arrived, damage, batches/expiry and freight.
 * "Purchase stock" is the same screen with the bill and payment recorded in the same step.
 */
class ReceiptController extends Controller
{
    public function index(Request $request): Response
    {
        Pager::remember($request, 'purchasing.receipts');
        $user = $request->user();
        $status = in_array($request->query('status'), ['pending_approval', 'posted', 'rejected'], true) ? $request->query('status') : null;

        return Inertia::render('purchasing/Receipts', [
            'receipts' => $this->listQuery($request)->with('supplier:id,name', 'purchaseOrder:id,number', 'receiver:id,name')->paginate($this->perPage($request))->withQueryString(),
            'showCost' => $user->hasPermission('catalog.costs.view'),
            'filters' => ['status' => $status ?? ''],
            'waiting' => GoodsReceipt::query()->whereIn('store_id', $user->storeIdsFor('inventory.adjust.approve'))->where('status', 'pending_approval')->count(),
        ]);
    }

    /** The goods received list as filtered on screen. The next / previous arrows on a receipt walk through this same list. */
    private function listQuery(Request $request): \Illuminate\Database\Eloquent\Builder
    {
        $user = $request->user();
        $stores = array_values(array_unique([...$user->storeIdsFor('purchasing.receive'), ...$user->storeIdsFor('inventory.purchase_stock'), ...$user->storeIdsFor('inventory.adjust.approve')]));
        $status = in_array($request->query('status'), ['pending_approval', 'posted', 'rejected'], true) ? $request->query('status') : null;

        return GoodsReceipt::query()->whereIn('store_id', $stores)->when($status, fn ($q, $s) => $q->where('status', $s))->latest('id');
    }

    public function create(Request $request): Response
    {
        $store = $this->currentStore($request);
        $this->allow($request, 'purchasing.receive', $store);
        $order = $request->integer('purchase_order_id') ? PurchaseOrder::query()->where('store_id', $store->id)->with('lines.variant:id,product_id,sku,name', 'lines.variant.product:id,name', 'lines.variantUnit.unit:id,code', 'supplier:id,name')->find($request->integer('purchase_order_id')) : null;

        return Inertia::render('purchasing/ReceiptForm', ['order' => $order, 'mode' => 'receive'] + $this->formProps($request, $store));
    }

    /** Purchase stock: the Goods received screen, with the bill and payment recorded in the same step. */
    public function purchaseStock(Request $request): Response
    {
        $store = $this->currentStore($request);
        $this->allow($request, 'inventory.purchase_stock', $store);

        return Inertia::render('purchasing/ReceiptForm', ['order' => null, 'mode' => 'purchase'] + $this->formProps($request, $store));
    }

    public function store(Request $request, ReceivingService $receiving): RedirectResponse
    {
        $store = $this->currentStore($request);
        abort_unless($request->user()->hasPermission('purchasing.receive', $store->id) || $request->user()->hasPermission('inventory.purchase_stock', $store->id), 403);
        $data = $request->validate([
            'supplier_id' => ['required', 'integer'], 'purchase_order_id' => ['nullable', 'integer'], 'location_id' => ['nullable', 'integer'], 'supplier_reference' => ['nullable', 'string', 'max:80'], 'notes' => ['nullable', 'string', 'max:1000'], 'is_blind' => ['boolean'],
            'lines' => ['required', 'array', 'min:1', 'max:500'], 'lines.*.variant_id' => ['required', 'integer'], 'lines.*.variant_unit_id' => ['required', 'integer'], 'lines.*.purchase_order_line_id' => ['nullable', 'integer'],
            'lines.*.quantity_received' => ['required', 'numeric', 'min:0'], 'lines.*.quantity_damaged' => ['nullable', 'numeric', 'min:0'], 'lines.*.unit_price' => ['required', 'numeric', 'min:0'],
            'lines.*.batch_no' => ['nullable', 'string', 'max:60'], 'lines.*.expiry_date' => ['nullable', 'date'], 'lines.*.quality_status' => ['nullable', 'in:accepted,quarantined'], 'lines.*.discrepancy_note' => ['nullable', 'string', 'max:300'],
            'landed_costs' => ['array'], 'landed_costs.*.type' => ['required', 'in:freight,loading,customs,other'], 'landed_costs.*.amount' => ['required', 'numeric', 'gt:0'], 'landed_costs.*.description' => ['nullable', 'string', 'max:200'], 'landed_costs.*.allocation_method' => ['nullable', 'in:value,quantity'],
            'settlement' => ['nullable', 'in:'.implode(',', ReceivingService::SETTLEMENTS)],
            'payment_method_id' => ['nullable', 'required_if:settlement,paid_now', 'integer'],
            'amount_paid' => ['nullable', 'numeric', 'gt:0'],
            'payment_reference' => ['nullable', 'string', 'max:80'],
        ], ['payment_method_id.required_if' => 'Choose what you paid with.']);
        $receipt = $receiving->receive($request->user(), $store, $data);

        $message = match (true) {
            $receipt->status === 'pending_approval' => "{$receipt->number} is waiting for a manager to approve it. Stock is added when it is approved.",
            $receipt->settlement === 'paid_now' => "Saved {$receipt->number}. Stock is added and the payment is recorded.",
            $receipt->settlement === 'on_credit' => "Saved {$receipt->number}. Stock is added and the amount is added to what you owe {$receipt->supplier->name}.",
            default => "Received {$receipt->number}. Stock is updated.",
        };

        return redirect()->route('purchasing.receipts.show', $receipt)->with('success', $message);
    }

    public function show(Request $request, GoodsReceipt $receipt): Response
    {
        $user = $request->user();
        abort_unless($user->hasPermission('purchasing.receive', $receipt->store_id) || $user->hasPermission('inventory.purchase_stock', $receipt->store_id) || $user->hasPermission('inventory.adjust.approve', $receipt->store_id), 403);

        return Inertia::render('purchasing/ReceiptShow', [
            'pager' => Pager::for($request, 'purchasing.receipts', $receipt->id, fn (Request $r) => $this->listQuery($r), fn (int $id) => route('purchasing.receipts.show', $id)),
            'receipt' => $receipt->load('lines.variant:id,product_id,sku,name', 'lines.variant.product:id,name', 'lines.variantUnit.unit:id,code', 'lines.batch:id,batch_no,expiry_date', 'landedCosts', 'supplier:id,name', 'purchaseOrder:id,number', 'location:id,name', 'receiver:id,name', 'approver:id,name', 'store:id,name',
                'paymentMethod:id,name,name_ur', 'bill:id,number,total,amount_paid,payment_status,due_date', 'payment:id,number,amount'),
            'showCost' => $user->hasPermission('catalog.costs.view') || $user->hasPermission('inventory.purchase_stock', $receipt->store_id),
            'canDecide' => $receipt->status === 'pending_approval' && $user->hasPermission('inventory.adjust.approve', $receipt->store_id) && ($receipt->received_by !== $user->id || $user->isOwner()),
        ]);
    }

    /** Approve or reject a purchase that is waiting for approval. */
    public function decide(Request $request, GoodsReceipt $receipt, ReceivingService $receiving): RedirectResponse
    {
        $data = $request->validate(['decision' => ['required', 'in:approve,reject'], 'reason' => ['nullable', 'required_if:decision,reject', 'string', 'max:300']], ['reason.required_if' => 'Say why you are rejecting it.']);
        $data['decision'] === 'approve' ? $receiving->approve($request->user(), $receipt) : $receiving->reject($request->user(), $receipt, $data['reason']);

        return back()->with('success', $data['decision'] === 'approve' ? 'Approved. Stock is added and the bill is recorded.' : 'Rejected. Nothing was added to stock.');
    }

    /** @return array<string, mixed> */
    private function formProps(Request $request, Store $store): array
    {
        $user = $request->user();
        $canApprove = $user->hasPermission('inventory.adjust.approve', $store->id);
        $threshold = (string) Settings::get('inventory.adjustment_approval_value', $store->id);

        return [
            'showCost' => $user->hasPermission('catalog.costs.view'),
            'can' => [
                'purchase' => $user->hasPermission('inventory.purchase_stock', $store->id),
                'receive' => $user->hasPermission('purchasing.receive', $store->id),
                'add_supplier' => $user->hasPermission('purchasing.suppliers.manage') || $user->hasPermission('inventory.purchase_stock', $store->id),
            ],
            // Purchases above this value wait for a manager (null: this person's purchases never wait).
            'approvalAbove' => $canApprove ? null : $threshold,
        ];
    }
}
