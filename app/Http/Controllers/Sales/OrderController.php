<?php

namespace App\Http\Controllers\Sales;

use App\Domain\Sales\SalesOrderService;
use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\ReasonCode;
use App\Models\SalesOrder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Phone, WhatsApp, field-agent and e-commerce orders: draft → confirmed
 * (stock reserved, Khata checked) → picking → packed → dispatched/invoiced →
 * delivered. Agents see only their own customers' orders unless allowed.
 */
class OrderController extends Controller
{
    private const FIELDS = [
        'customer_id' => ['required', 'integer'],
        'channel' => ['required', 'in:phone,whatsapp,field_agent,counter,ecommerce'],
        'fulfillment_type' => ['required', 'in:pickup,delivery'],
        'delivery_address' => ['nullable', 'string', 'max:500'],
        'delivery_area' => ['nullable', 'string', 'max:120'],
        'delivery_phone' => ['nullable', 'string', 'max:30'],
        'requested_delivery_at' => ['nullable', 'date'],
        'payment_preference' => ['nullable', 'in:cod,khata,prepaid,wallet'],
        'delivery_charge' => ['nullable', 'numeric', 'min:0'],
        'sales_agent_id' => ['nullable', 'integer'],
        'notes' => ['nullable', 'string', 'max:1000'],
        'idempotency_key' => ['nullable', 'string', 'max:64'],
        'lines' => ['required', 'array', 'min:1', 'max:300'],
        'lines.*.variant_id' => ['required', 'integer'],
        'lines.*.variant_unit_id' => ['required', 'integer'],
        'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
        'lines.*.unit_price' => ['nullable', 'numeric', 'min:0'],
        'lines.*.discount' => ['nullable', 'numeric', 'min:0'],
    ];

    public function index(Request $request): Response
    {
        $user = $request->user();
        $stores = $user->storeIdsFor('sales.orders.view');
        $q = trim((string) $request->query('q'));
        $orders = $this->scoped($request, SalesOrder::query())->whereIn('store_id', $stores)->with('customer:id,name,code', 'salesAgent:id,name')
            ->when($request->query('status'), fn ($w, $s) => $w->where('status', $s))
            ->when($request->query('channel'), fn ($w, $c) => $w->where('channel', $c))
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('number', 'ilike', "%{$q}%")->orWhereHas('customer', fn ($c) => $c->where('name', 'ilike', "%{$q}%"))))
            ->latest('id')->paginate($this->perPage($request))->withQueryString();
        $counts = $this->scoped($request, SalesOrder::query())->whereIn('store_id', $stores)->whereNotIn('status', ['completed', 'cancelled'])->groupBy('status')->selectRaw('status, COUNT(*) AS n')->pluck('n', 'status');

        return Inertia::render('sales/Orders', ['orders' => $orders, 'counts' => $counts, 'filters' => $request->only(['q', 'status', 'channel'])]);
    }

    public function create(Request $request): Response
    {
        $this->allow($request, 'sales.orders.manage', $this->currentStore($request));

        return Inertia::render('sales/OrderForm', ['order' => null, 'customer' => $request->integer('customer_id') ? Contact::query()->customers()->find($request->integer('customer_id'), ['id', 'name', 'code', 'phone', 'address', 'area']) : null]);
    }

    public function store(Request $request, SalesOrderService $orders): RedirectResponse
    {
        $store = $this->currentStore($request);
        $this->allow($request, 'sales.orders.manage', $store);
        $order = $orders->save($request->user(), $store, $request->validate(self::FIELDS));

        return redirect()->route('sales.orders.show', $order)->with('success', "Order {$order->number} saved as draft.");
    }

    public function show(Request $request, SalesOrder $order): Response
    {
        $this->canSee($request, $order);
        $order->load(['lines.variant:id,product_id,sku,name,name_ur', 'lines.variant.product:id,name', 'lines.variantUnit.unit:id,code', 'customer.customerProfile', 'salesAgent:id,name', 'invoices:id,sales_order_id,number,total,payment_status', 'deliveries.rider:id,name', 'deliveries.events', 'cancelReason:id,label', 'creator:id,name']);
        $user = $request->user();

        return Inertia::render('sales/OrderShow', [
            'order' => $order,
            'can' => ['manage' => $user->hasPermission('sales.orders.manage', $order->store_id), 'confirm' => $user->hasPermission('sales.orders.confirm', $order->store_id), 'deliver' => $user->hasPermission('sales.deliveries.manage', $order->store_id)],
            'cancelReasons' => ReasonCode::query()->where('type', 'order_cancel')->where('is_active', true)->get(['id', 'label']),
        ]);
    }

    public function edit(Request $request, SalesOrder $order): Response
    {
        $this->allow($request, 'sales.orders.manage', $order->store_id);

        return Inertia::render('sales/OrderForm', ['order' => $order->load('lines.variant:id,product_id,sku,name', 'lines.variant.product:id,name', 'lines.variantUnit.unit:id,code', 'customer:id,name,code,phone,address,area')]);
    }

    public function update(Request $request, SalesOrder $order, SalesOrderService $orders): RedirectResponse
    {
        $this->allow($request, 'sales.orders.manage', $order->store_id);
        $orders->save($request->user(), $order->store, $request->validate(self::FIELDS), $order);

        return redirect()->route('sales.orders.show', $order)->with('success', 'Order updated.');
    }

    public function confirm(Request $request, SalesOrder $order, SalesOrderService $orders): RedirectResponse
    {
        $this->allow($request, 'sales.orders.confirm', $order->store_id);
        $orders->confirm($request->user(), $order, $request->string('approval_token')->toString() ?: null);

        return back()->with('success', "Order {$order->number} confirmed; stock is reserved.");
    }

    public function advance(Request $request, SalesOrder $order, SalesOrderService $orders): RedirectResponse
    {
        $this->allow($request, 'sales.orders.manage', $order->store_id);
        $orders->advance($request->user(), $order, $request->validate(['status' => ['required', 'in:picking,packed']])['status']);

        return back();
    }

    public function dispatch(Request $request, SalesOrder $order, SalesOrderService $orders): RedirectResponse
    {
        $this->allow($request, 'sales.orders.confirm', $order->store_id);
        $invoice = $orders->dispatch($request->user(), $order, $request->integer('rider_id') ?: null);

        return back()->with('success', "Invoice {$invoice->number} created".($order->fulfillment_type === 'delivery' ? ' and sent for delivery.' : '.'));
    }

    public function cancel(Request $request, SalesOrder $order, SalesOrderService $orders): RedirectResponse
    {
        $this->allow($request, 'sales.orders.manage', $order->store_id);
        $orders->cancel($request->user(), $order, $request->validate(['reason_id' => ['required', 'integer']])['reason_id']);

        return back()->with('success', 'Order cancelled; reserved stock released.');
    }

    private function scoped(Request $request, $query)
    {
        $user = $request->user();
        if ($user->hasPermission('sales.orders.view_all_agents')) {
            return $query;
        }
        $me = $user->employee?->id;

        return $query->where(fn ($q) => $q->where('created_by', $user->id)->when($me, fn ($w) => $w->orWhere('sales_agent_id', $me)));
    }

    private function canSee(Request $request, SalesOrder $order): void
    {
        $this->allow($request, 'sales.orders.view', $order->store_id);
        abort_unless($this->scoped($request, SalesOrder::query())->whereKey($order->id)->exists(), 403);
    }
}
