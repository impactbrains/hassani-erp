<?php

namespace App\Http\Controllers\Pos;

use App\Domain\Catalog\BarcodeLookup;
use App\Domain\Catalog\CatalogSearch;
use App\Domain\Inventory\Availability;
use App\Domain\Pos\CheckoutService;
use App\Domain\Pos\HeldCartService;
use App\Domain\Pos\PosWorkspaceService;
use App\Domain\Pos\ReceiptData;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\HeldCart;
use App\Models\PaymentMethod;
use App\Models\ReasonCode;
use App\Models\Register;
use App\Models\SalesInvoice;
use App\Models\SalesOrder;
use App\Models\Shift;
use App\Support\Audit;
use App\Support\Settings\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The POS. The screen is full-screen and keyboard/scanner first; the
 * browser only proposes a cart and the server prices and posts it.
 */
class PosController extends Controller
{
    public function screen(Request $request): Response
    {
        $store = $this->currentStore($request);
        $user = $request->user();
        $registers = Register::query()->where('store_id', $store->id)->where('is_active', true)->orderBy('name')->get(['id', 'name']);
        $shift = Shift::query()->where('store_id', $store->id)->where('status', 'open')->where('opened_by', $user->id)->with('register:id,name')->first();
        $workspace = app(PosWorkspaceService::class)->forUser($user, $store->id);
        $joinedShift = null;
        if ($shift === null && $workspace?->shift_id && $user->hasPermission('pos.shift.join', $store->id)) {
            // A refresh must not drop a cashier who joined someone else's open shift.
            $joinedShift = Shift::query()->where('store_id', $store->id)->where('status', 'open')->with(['register:id,name', 'opener:id,name'])->find($workspace->shift_id);
            $shift = $joinedShift;
        }
        $busy = Shift::query()->where('store_id', $store->id)->where('status', 'open')->with('opener:id,name')->get(['id', 'register_id', 'opened_by'])
            ->mapWithKeys(fn ($s) => [$s->register_id => $s->opener?->name]);
        $can = fn (string $p) => $user->hasPermission($p, $store->id);

        return Inertia::render('pos/Terminal', [
            'store' => $store->only(['id', 'name', 'name_ur', 'code']),
            'registers' => $registers->map(fn ($r) => $r->only(['id', 'name']) + ['busy_with' => $busy[$r->id] ?? null]),
            'shift' => $shift ? ['id' => $shift->id, 'number' => $shift->number, 'register_id' => $shift->register_id, 'register' => $shift->register?->name, 'opened_at' => $shift->opened_at] + ($joinedShift ? ['joined' => true, 'opener' => $joinedShift->opener?->name] : []) : null,
            'workspace' => $workspace ? ['state' => $workspace->state, 'version' => $workspace->version] : null,
            'maxTabs' => PosWorkspaceService::MAX_TABS,
            'paymentMethods' => PaymentMethod::query()->where('is_active', true)->where('available_in_pos', true)->orderBy('sort_order')->get(['id', 'code', 'name', 'name_ur', 'type', 'requires_reference', 'requires_verification', 'terminal_labels']),
            'reasons' => ReasonCode::query()->whereIn('type', ['discount', 'return', 'payout', 'no_sale', 'cash_in', 'price_override'])->where('is_active', true)->orderBy('label')->get(['id', 'type', 'code', 'label', 'label_ur', 'requires_note'])->groupBy('type'),
            'settings' => [
                'rounding_step' => Settings::get('pricing.rounding_step', $store->id),
                'max_discount_percent' => Settings::get('pricing.max_discount_percent', $store->id),
                'auto_print' => Settings::get('pos.auto_print', $store->id),
                'receipt_width' => Settings::get('pos.receipt_width', $store->id),
                'receipt_language' => Settings::get('pos.receipt_language', $store->id),
                'denominations' => Settings::get('pos.denominations', $store->id),
                'blind_close' => Settings::get('pos.blind_close', $store->id),
            ],
            'unlistedItem' => app(\App\Domain\Catalog\CatalogSearch::class)->card(app(\App\Domain\Catalog\UnlistedItems::class)->variant(), $store->id, null, null, []),
            'can' => [
                'discount' => $can('pos.discount'), 'approve_discount' => $can('pos.discount.approve'), 'override_price' => $can('pos.price.override'),
                'credit' => $can('pos.sell_on_credit'), 'refund' => $can('pos.refund'), 'hold' => $can('pos.hold'), 'payout' => $can('pos.cash.payout'),
                'drop' => $can('pos.cash.drop'), 'close' => $can('pos.shift.close'), 'collect' => $can('sales.payments.receive'),
                'join' => $can('pos.shift.join'),
            ],
        ]);
    }

    /**
     * Steps into a counter that already has an open shift. Nothing is counted or
     * opened: the drawer belongs to the shift that is already running, and sales
     * made from here are posted on it under the joiner's own name. Allowed for
     * whoever opened the shift, or anyone who may sell and holds pos.shift.join.
     */
    public function join(Request $request): JsonResponse
    {
        $data = $request->validate(['register_id' => ['required', 'integer']]);
        $user = $request->user();
        $store = $this->currentStore($request);
        $this->allow($request, 'pos.sell', $store->id);

        $shift = Shift::query()->where('store_id', $store->id)->where('register_id', (int) $data['register_id'])->where('status', 'open')
            ->with(['register:id,name', 'opener:id,name'])->first();
        if ($shift === null) {
            throw new BusinessRuleException('That counter has no open shift to join. Open a shift on it instead.', 'shift_not_open');
        }
        $joined = $shift->opened_by !== $user->id;
        if ($joined) {
            $this->allow($request, 'pos.shift.join', $store->id);
            Audit::record('pos.shift_joined', "Joined {$shift->number} on {$shift->register?->name}, opened by {$shift->opener?->name}", $shift, storeId: $store->id);
            app(PosWorkspaceService::class)->rememberShift($user, $store->id, $shift);
        }

        return response()->json([
            'id' => $shift->id, 'number' => $shift->number, 'register_id' => $shift->register_id, 'register' => $shift->register?->name,
            'opened_at' => $shift->opened_at, 'joined' => $joined, 'opener' => $shift->opener?->name,
        ]);
    }

    public function quote(Request $request, CheckoutService $checkout): JsonResponse
    {
        return response()->json($checkout->quote($request->user(), $this->validated($request, quote: true)));
    }

    public function checkout(Request $request, CheckoutService $checkout, ReceiptData $receipts): JsonResponse
    {
        $invoice = $checkout->checkout($request->user(), $this->validated($request));

        return response()->json(['invoice_id' => $invoice->id, 'number' => $invoice->number, 'receipt' => $receipts->forInvoice($invoice->fresh())]);
    }

    public function receipt(Request $request, SalesInvoice $invoice, ReceiptData $receipts): JsonResponse
    {
        $this->allow($request, 'sales.invoices.view', $invoice->store_id);
        Audit::record('pos.receipt_reprinted', "Reprinted {$invoice->number}", $invoice, storeId: $invoice->store_id);

        return response()->json($receipts->forInvoice($invoice, reprint: true));
    }

    /** Last receipts of this POS counter for reprint and returns. */
    public function recent(Request $request): JsonResponse
    {
        $store = $this->currentStore($request);
        $this->allow($request, 'pos.sell', $store->id);
        $q = trim((string) $request->query('q'));

        return response()->json(SalesInvoice::query()->where('store_id', $store->id)->where('status', 'posted')
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('number', 'ilike', "%{$q}%")->orWhere('customer_name', 'ilike', "%{$q}%")))
            ->latest('id')->limit(20)->get(['id', 'number', 'total', 'customer_name', 'posted_at', 'payment_status']));
    }

    public function held(Request $request): JsonResponse
    {
        $store = $this->currentStore($request);

        return response()->json(HeldCart::query()->where('store_id', $store->id)->where('status', 'held')->where('expires_at', '>', now())
            ->with('user:id,name')->latest()->get(['id', 'label', 'estimated_total', 'user_id', 'customer_id', 'created_at']));
    }

    public function hold(Request $request, HeldCartService $carts): JsonResponse
    {
        $data = $request->validate(['register_id' => ['required', 'integer'], 'cart' => ['required', 'array'], 'cart.lines' => ['required', 'array', 'min:1'], 'label' => ['nullable', 'string', 'max:60'], 'customer_id' => ['nullable', 'integer'], 'estimated_total' => ['required', 'numeric']]);
        $register = Register::query()->with('store')->findOrFail($data['register_id']);
        $held = $carts->hold($request->user(), $register, $data['cart'], $data['label'] ?? null, $data['customer_id'] ?? null, (string) $data['estimated_total']);

        return response()->json(['id' => $held->id, 'label' => $held->label]);
    }

    public function resume(Request $request, int $cart, HeldCartService $carts): JsonResponse
    {
        $held = $carts->resume($request->user(), $cart, $this->currentStore($request)->id);

        return response()->json(['cart' => $held->cart, 'customer_id' => $held->customer_id, 'label' => $held->label]);
    }

    public function discard(Request $request, int $cart, HeldCartService $carts): JsonResponse
    {
        $carts->discard($request->user(), $cart, $this->currentStore($request)->id, $request->string('reason')->toString() ?: null);

        return response()->json(['ok' => true]);
    }

    /**
     * One scan from a USB/Bluetooth scanner or the camera. Exact matches only (never a search):
     * a product barcode or SKU adds the item; a receipt or order number opens that document.
     */
    public function scan(Request $request, BarcodeLookup $barcodes, CatalogSearch $catalog, Availability $availability): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:120'], 'customer_id' => ['nullable', 'integer']]);
        $user = $request->user();
        $store = $this->currentStore($request);
        $this->allow($request, 'pos.sell', $store->id);
        $code = trim($data['code']);

        if ($hit = $barcodes->find($code)) {
            $customer = ! empty($data['customer_id']) ? Contact::query()->with('customerProfile')->find($data['customer_id']) : null;
            $stock = $availability->availableMany($store->id, [$hit['variant']->id]);
            $pricing = new \App\Domain\Catalog\PriceContext($store->id, $customer, $store->businessDate());

            return response()->json(['type' => 'product', 'product' => $catalog->card($hit['variant'], $store->id, $customer?->customerProfile?->price_book_id, $hit['unit']->id, $stock, 'sale', $pricing)]);
        }
        $lower = mb_strtolower($code);
        if ($user->hasPermission('sales.invoices.view', $store->id)) {
            $invoice = SalesInvoice::query()->where('store_id', $store->id)->whereRaw('lower(number) = ?', [$lower])->first(['id', 'number']);
            if ($invoice) {
                return response()->json(['type' => 'receipt', 'id' => $invoice->id, 'number' => $invoice->number]);
            }
        }
        if ($user->hasAnyPermission(['sales.orders.view', 'sales.orders.manage'])) {
            $order = SalesOrder::query()->where('store_id', $store->id)->whereRaw('lower(number) = ?', [$lower])->first(['id', 'number']);
            if ($order) {
                return response()->json(['type' => 'order', 'id' => $order->id, 'number' => $order->number, 'url' => route('sales.orders.show', $order->id, false)]);
            }
        }

        return response()->json(['type' => 'none', 'code' => $code]);
    }

    /** Saves the open cart tabs (see PosWorkspaceService for the two-screen rule). */
    public function saveWorkspace(Request $request, PosWorkspaceService $workspaces): JsonResponse
    {
        $data = $request->validate([
            'state' => ['required', 'array'],
            'state.carts' => ['present', 'array', 'max:'.PosWorkspaceService::MAX_TABS],
            'state.active_id' => ['nullable', 'string', 'max:64'],
            'base_version' => ['nullable', 'integer'],
            'register_id' => ['nullable', 'integer'],
            'force' => ['nullable', 'boolean'],
        ]);
        if (strlen((string) json_encode($data['state'])) > 1_500_000) {
            throw new BusinessRuleException('These carts are too large to save. Finish or close some of them.', 'workspace_too_large');
        }

        return response()->json($workspaces->save($request->user(), $this->currentStore($request)->id, $data['state'], $data['base_version'] ?? null, $data['register_id'] ?? null, (bool) ($data['force'] ?? false)));
    }

    /** Logs a removed line (void before payment) when the cart is cleared without a sale. */
    public function voidLog(Request $request): JsonResponse
    {
        $data = $request->validate(['lines' => ['required', 'array', 'max:200'], 'lines.*.description' => ['required', 'string', 'max:200'], 'lines.*.quantity' => ['required'], 'reason' => ['nullable', 'string', 'max:200']]);
        $store = $this->currentStore($request);
        Audit::record('pos.cart_discarded', 'Cleared cart with '.count($data['lines']).' line(s) without a sale', null, new: $data, storeId: $store->id);

        return response()->json(['ok' => true]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $quote = false): array
    {
        return $request->validate([
            'idempotency_key' => [$quote ? 'nullable' : 'required', 'string', 'max:64'],
            'register_id' => ['required', 'integer'],
            'customer_id' => ['nullable', 'integer'],
            'lines' => ['required', 'array', 'min:1', 'max:300'],
            'lines.*.variant_id' => ['required', 'integer'],
            'lines.*.variant_unit_id' => ['required', 'integer'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'max:1000000'],
            'lines.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'lines.*.discount' => ['nullable', 'array'],
            'lines.*.discount.type' => ['nullable', 'in:amount,percent'],
            'lines.*.discount.value' => ['nullable', 'numeric', 'min:0'],
            'lines.*.discount_reason_id' => ['nullable', 'integer'],
            'lines.*.price_approval_token' => ['nullable', 'string'],
            'lines.*.description' => ['nullable', 'string', 'max:120'],
            'lines.*.barcode' => ['nullable', 'string', 'max:64'],
            'cart_discount' => ['nullable', 'array'],
            'cart_discount.type' => ['nullable', 'in:amount,percent'],
            'cart_discount.value' => ['nullable', 'numeric', 'min:0'],
            'cart_discount_reason_id' => ['nullable', 'integer'],
            'discount_approval_token' => ['nullable', 'string'],
            'credit_approval_token' => ['nullable', 'string'],
            'payments' => ['array', 'max:6'],
            'payments.*.payment_method_id' => ['required', 'integer'],
            'payments.*.amount' => ['required', 'numeric', 'min:0'],
            'payments.*.tendered' => ['nullable', 'numeric', 'min:0'],
            'payments.*.reference' => ['nullable', 'string', 'max:80'],
            'payments.*.terminal_label' => ['nullable', 'string', 'max:40'],
            'expected_total' => ['nullable', 'numeric'],
            'removed_lines' => ['array', 'max:200'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);
    }
}