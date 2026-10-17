<?php

namespace App\Http\Controllers\Purchasing;

use App\Domain\Purchasing\PurchaseOrderService;
use App\Http\Controllers\Controller;
use App\Models\PurchaseOrder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PurchaseOrderController extends Controller
{
    private const RULES = ['supplier_id' => ['required', 'integer'], 'expected_date' => ['nullable', 'date'], 'notes' => ['nullable', 'string', 'max:1000'], 'source' => ['nullable', 'in:manual,replenishment'],
        'lines' => ['required', 'array', 'min:1', 'max:500'], 'lines.*.variant_id' => ['required', 'integer'], 'lines.*.variant_unit_id' => ['required', 'integer'], 'lines.*.quantity' => ['required', 'numeric', 'gt:0'], 'lines.*.unit_price' => ['required', 'numeric', 'min:0'], 'lines.*.tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100']];

    public function index(Request $request): Response
    {
        $stores = $request->user()->storeIdsFor('purchasing.orders.manage');
        $stores = array_values(array_unique([...$stores, ...$request->user()->storeIdsFor('purchasing.orders.approve'), ...$request->user()->storeIdsFor('purchasing.receive')]));

        return Inertia::render('purchasing/Orders', [
            'orders' => PurchaseOrder::query()->whereIn('store_id', $stores)->with('supplier:id,name', 'store:id,name', 'creator:id,name')
                ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))->when($request->query('supplier_id'), fn ($q, $s) => $q->where('supplier_id', $s))
                ->latest('id')->paginate($this->perPage($request))->withQueryString(),
            'filters' => $request->only(['status', 'supplier_id']),
        ]);
    }

    public function create(Request $request): Response
    {
        $this->allow($request, 'purchasing.orders.manage', $this->currentStore($request));
        $prefill = json_decode((string) $request->query('prefill', '[]'), true) ?: [];

        return Inertia::render('purchasing/OrderForm', ['order' => null, 'prefill' => $prefill]);
    }

    public function store(Request $request, PurchaseOrderService $orders): RedirectResponse
    {
        $store = $this->currentStore($request);
        $this->allow($request, 'purchasing.orders.manage', $store);
        $order = $orders->save($request->user(), $store, $request->validate(self::RULES));

        return redirect()->route('purchasing.orders.show', $order)->with('success', "Purchase order {$order->number} saved.");
    }

    public function show(Request $request, PurchaseOrder $order, PurchaseOrderService $orders): Response
    {
        abort_unless($request->user()->hasAnyPermission(['purchasing.orders.manage', 'purchasing.orders.approve', 'purchasing.receive']) && in_array($order->store_id, $request->user()->accessibleStoreIds(), true), 403);

        return Inertia::render('purchasing/OrderShow', [
            'order' => $order->load('lines.variant:id,product_id,sku,name', 'lines.variant.product:id,name', 'lines.variantUnit.unit:id,code', 'supplier:id,name,phone', 'store:id,name', 'creator:id,name', 'approver:id,name', 'receipts:id,purchase_order_id,number,business_date,goods_value'),
            'can' => ['manage' => $request->user()->hasPermission('purchasing.orders.manage', $order->store_id), 'approve' => $orders->canApprove($request->user(), $order), 'receive' => $request->user()->hasPermission('purchasing.receive', $order->store_id)],
        ]);
    }

    public function edit(Request $request, PurchaseOrder $order): Response
    {
        $this->allow($request, 'purchasing.orders.manage', $order->store_id);

        return Inertia::render('purchasing/OrderForm', ['order' => $order->load('lines.variant:id,product_id,sku,name', 'lines.variant.product:id,name', 'lines.variantUnit.unit:id,code', 'supplier:id,name'), 'prefill' => []]);
    }

    public function update(Request $request, PurchaseOrder $order, PurchaseOrderService $orders): RedirectResponse
    {
        $this->allow($request, 'purchasing.orders.manage', $order->store_id);
        $orders->save($request->user(), $order->store, $request->validate(self::RULES), $order);

        return redirect()->route('purchasing.orders.show', $order)->with('success', 'Saved.');
    }

    public function action(Request $request, PurchaseOrder $order, PurchaseOrderService $orders): RedirectResponse
    {
        $data = $request->validate(['action' => ['required', 'in:submit,approve,send,cancel,close'], 'reason' => ['nullable', 'required_if:action,cancel,close', 'string', 'max:300']]);
        $user = $request->user();
        match ($data['action']) {
            'submit' => $orders->submit($user, $order),
            'approve' => $orders->approve($user, $order),
            'send' => $orders->markSent($user, $order),
            'cancel' => $orders->cancel($user, $order, $data['reason']),
            'close' => $orders->close($user, $order, $data['reason']),
        };

        return back()->with('success', 'Purchase order updated.');
    }
}
