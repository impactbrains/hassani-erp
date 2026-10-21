<?php

namespace App\Domain\Sales;

use App\Domain\Catalog\PriceResolver;
use App\Domain\Catalog\TaxResolver;
use App\Domain\Inventory\Availability;
use App\Domain\Inventory\Units;
use App\Exceptions\BusinessRuleException;
use App\Models\BundleComponent;
use App\Models\Contact;
use App\Models\Delivery;
use App\Models\DeliveryEvent;
use App\Models\ProductVariant;
use App\Models\ReasonCode;
use App\Models\SalesInvoice;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\StockReservation;
use App\Models\Store;
use App\Models\User;
use App\Support\Audit;
use App\Support\Decimal;
use App\Support\Sequences;
use App\Support\Settings\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Wholesale, phone, field-agent and e-commerce orders.
 *
 *   draft -> confirmed (stock reserved) -> picking -> packed -> dispatched
 *         -> delivered -> completed;  or cancelled (reservation released)
 *
 * The invoice is issued when the order is dispatched (or handed over for
 * pickup); that is when stock leaves and the sale is recognised. Booked,
 * invoiced and collected values are therefore always separate figures.
 */
final class SalesOrderService
{
    public function __construct(
        private readonly PriceResolver $prices,
        private readonly \App\Domain\Catalog\PriceRules $rules,
        private readonly TaxResolver $taxes,
        private readonly CartPricer $pricer,
        private readonly Availability $availability,
        private readonly CreditControl $credit,
        private readonly SaleRecorder $recorder,
    ) {
    }

    /**
     * @param  array{customer_id: int, channel?: string, fulfillment_type?: string, delivery_address?: string|null, delivery_area?: string|null,
     *     delivery_phone?: string|null, requested_delivery_at?: string|null, payment_preference?: string|null, delivery_charge?: string|null,
     *     sales_agent_id?: int|null, notes?: string|null, idempotency_key?: string|null, external_reference?: string|null, api_client_id?: int|null,
     *     lines: list<array{variant_id: int, variant_unit_id: int, quantity: string, unit_price?: string|null, discount?: string|null}>}  $data
     */
    public function save(?User $user, Store $store, array $data, ?SalesOrder $order = null): SalesOrder
    {
        if ($user && ! $user->hasPermission('sales.orders.manage', $store->id)) {
            throw new BusinessRuleException('You are not allowed to create orders.', 'not_permitted');
        }
        if ($order && $order->status !== 'draft') {
            throw new BusinessRuleException('Only draft orders can be edited. Cancel and re-create a confirmed order instead.', 'invalid_status');
        }
        if (! empty($data['idempotency_key']) && ! $order && ($existing = SalesOrder::query()->where('idempotency_key', $data['idempotency_key'])->first())) {
            return $existing;
        }
        $customer = Contact::query()->customers()->with('customerProfile')->findOrFail($data['customer_id']);
        if (empty($data['lines'])) {
            throw new BusinessRuleException('Add at least one product.', 'no_lines');
        }

        return DB::transaction(function () use ($user, $store, $data, $order, $customer) {
            $date = $store->businessDate();
            $priceBookId = $customer->customerProfile?->price_book_id;
            $order ??= new SalesOrder([
                'store_id' => $store->id,
                'number' => Sequences::next('sales_order', $store->id, $store->code),
                'status' => 'draft',
                'created_by' => $user?->id,
                'business_date' => $date,
                'idempotency_key' => $data['idempotency_key'] ?? null,
                'api_client_id' => $data['api_client_id'] ?? null,
            ]);
            $order->fill([
                'customer_id' => $customer->id,
                'channel' => $data['channel'] ?? 'phone',
                'price_book_id' => $priceBookId,
                'sales_agent_id' => $data['sales_agent_id'] ?? $customer->customerProfile?->sales_agent_id,
                'fulfillment_type' => $data['fulfillment_type'] ?? 'pickup',
                'delivery_address' => $data['delivery_address'] ?? $customer->address,
                'delivery_area' => $data['delivery_area'] ?? $customer->area,
                'delivery_phone' => $data['delivery_phone'] ?? $customer->phone,
                'requested_delivery_at' => $data['requested_delivery_at'] ?? null,
                'payment_preference' => $data['payment_preference'] ?? 'cod',
                'delivery_charge' => Decimal::money($data['delivery_charge'] ?? '0'),
                'notes' => $data['notes'] ?? null,
                'external_reference' => $data['external_reference'] ?? null,
            ]);
            $order->save();
            $order->lines()->delete();

            $pricerLines = [];
            $rows = [];
            $prepared = [];
            foreach (array_values($data['lines']) as $index => $raw) {
                $variant = ProductVariant::query()->with('product', 'units.unit')->where('is_active', true)->findOrFail($raw['variant_id']);
                $unit = $variant->units->firstWhere('id', (int) $raw['variant_unit_id']);
                if ($unit === null || ! $unit->can_sell) {
                    throw new BusinessRuleException("{$variant->displayName()} cannot be sold in that pack size.", 'unit_not_sellable');
                }
                $quantity = Decimal::qty($raw['quantity']);
                $resolved = $this->prices->resolve($variant, $unit, $priceBookId, $quantity);
                if ($resolved === null) {
                    throw new BusinessRuleException("{$variant->displayName()} has no price for this customer.", 'price_missing');
                }
                $prepared[$index] = ['raw' => $raw, 'variant' => $variant, 'unit' => $unit, 'quantity' => $quantity, 'base_quantity' => Units::toBase($quantity, $unit), 'baseline' => $resolved['price']];
            }
            // Price rules apply to orders taken by staff. Orders from the website / app (API) keep the online prices.
            $fromApi = ! empty($data['api_client_id']) || ($data['channel'] ?? null) === 'ecommerce';
            $ruled = $fromApi ? [] : $this->rules->apply($prepared, new \App\Domain\Catalog\PriceContext($store->id, $customer, $date));
            foreach ($prepared as $index => ['raw' => $raw, 'variant' => $variant, 'unit' => $unit, 'quantity' => $quantity, 'base_quantity' => $base, 'baseline' => $baseline]) {
                $unitPrice = $ruled[$index]['price'] ?? $baseline;
                $ruleId = $ruled[$index]['rule_id'] ?? null;
                // The order form fills each line with the plain list price; that is not a price change when a rule lowered it.
                $sent = isset($raw['unit_price']) && $raw['unit_price'] !== null && $raw['unit_price'] !== '' ? Decimal::money($raw['unit_price']) : null;
                if ($sent !== null && $ruleId !== null && Decimal::eq($sent, $baseline)) {
                    $sent = null;
                }
                if ($sent !== null && ! Decimal::eq($sent, $unitPrice)) {
                    if (! $user?->hasPermission('catalog.prices.manage', $store->id) && ! $user?->hasPermission('pos.price.override', $store->id)) {
                        throw new BusinessRuleException('You are not allowed to change prices on orders.', 'price_override_not_permitted');
                    }
                    $unitPrice = $sent;
                    $ruleId = null;
                }
                $tax = $this->taxes->for($variant->product, $date);
                $discount = isset($raw['discount']) && $raw['discount'] !== '' && $raw['discount'] !== null ? ['type' => 'amount', 'value' => (string) $raw['discount']] : null;
                $pricerLines[] = ['key' => $index, 'unit_price' => $unitPrice, 'quantity' => $quantity, 'discount' => $discount, 'tax_rate' => $tax['rate'], 'tax_inclusive' => $tax['inclusive']];
                $rows[] = compact('variant', 'unit', 'quantity', 'base', 'unitPrice', 'tax', 'ruleId');
            }
            $pricing = $this->pricer->price($pricerLines);
            if (Decimal::gt($pricing['max_discount_percent'], (string) Settings::get('pricing.max_discount_percent', $store->id))
                && ! $user?->hasPermission('pos.discount.approve', $store->id)) {
                throw new BusinessRuleException('Discounts above the limit need a manager.', 'discount_not_permitted');
            }
            foreach ($rows as $index => $row) {
                $priced = $pricing['lines'][$index];
                SalesOrderLine::create([
                    'sales_order_id' => $order->id,
                    'variant_id' => $row['variant']->id,
                    'variant_unit_id' => $row['unit']->id,
                    'description' => $row['variant']->displayName(),
                    'quantity' => $row['quantity'],
                    'unit_factor' => $row['unit']->factor,
                    'base_quantity' => $row['base'],
                    'unit_price' => $row['unitPrice'],
                    'discount_amount' => $priced['line_discount'],
                    'tax_rate' => $row['tax']['rate'],
                    'tax_inclusive' => $row['tax']['inclusive'],
                    'tax_amount' => $priced['tax_amount'],
                    'line_total' => $priced['line_total'],
                    'price_rule_id' => $row['ruleId'],
                ]);
            }
            $order->update([
                'subtotal' => $pricing['subtotal'],
                'discount_total' => $pricing['discount_total'],
                'tax_total' => $pricing['tax_total'],
                'total' => Decimal::money(Decimal::add($pricing['total_before_rounding'], $order->delivery_charge)),
            ]);
            Audit::record('sales.order_saved', "Saved order {$order->number} for {$customer->name}", $order);

            return $order->fresh('lines');
        });
    }

    /** Confirms the order and reserves its stock. */
    public function confirm(?User $user, SalesOrder $order, ?string $creditApprovalToken = null): SalesOrder
    {
        if ($user && ! $user->hasPermission('sales.orders.confirm', $order->store_id) && ! $user->hasPermission('sales.orders.manage', $order->store_id)) {
            throw new BusinessRuleException('You are not allowed to confirm orders.', 'not_permitted');
        }

        return DB::transaction(function () use ($user, $order, $creditApprovalToken) {
            $order = SalesOrder::query()->lockForUpdate()->findOrFail($order->id);
            if ($order->status !== 'draft') {
                throw new BusinessRuleException("Order {$order->number} is already {$order->status}.", 'invalid_status');
            }
            $date = $order->store->businessDate();
            if ($order->payment_preference === 'khata' && $user) {
                $this->credit->authorize($order->customer()->with('customerProfile')->first(), $order->total, $order->store_id, $user, $creditApprovalToken, $date);
            }

            $needs = [];
            foreach ($order->lines()->with('variant.product')->get() as $line) {
                foreach ($this->stockParts($line->variant, $line->base_quantity) as $variantId => $quantity) {
                    $needs[$variantId] = Decimal::add($needs[$variantId] ?? '0', $quantity);
                }
            }
            ksort($needs);
            $hours = (int) Settings::get('sales.reservation_hours');
            foreach ($needs as $variantId => $quantity) {
                $variant = ProductVariant::query()->with('product')->find($variantId);
                $this->availability->assertAvailable($order->store_id, $variant, Decimal::qty($quantity), $date);
                StockReservation::create([
                    'store_id' => $order->store_id,
                    'variant_id' => $variantId,
                    'quantity' => Decimal::qty($quantity),
                    'source_type' => $order->getMorphClass(),
                    'source_id' => $order->id,
                    'status' => 'active',
                    'expires_at' => $hours > 0 ? now()->addHours($hours) : null,
                ]);
            }
            $order->update(['status' => 'confirmed', 'confirmed_at' => now()]);
            Audit::record('sales.order_confirmed', "Confirmed order {$order->number}; stock reserved", $order);

            return $order;
        });
    }

    public function advance(User $user, SalesOrder $order, string $status): SalesOrder
    {
        $allowed = ['confirmed' => ['picking'], 'picking' => ['packed'], 'packed' => [], 'dispatched' => [], 'delivered' => ['completed']];
        if (! in_array($status, $allowed[$order->status] ?? [], true)) {
            throw new BusinessRuleException("An order that is {$order->status} cannot move to {$status}.", 'invalid_transition');
        }
        if (! $user->hasPermission('sales.orders.manage', $order->store_id) && ! $user->hasPermission('sales.deliveries.manage', $order->store_id)) {
            throw new BusinessRuleException('You are not allowed to update orders.', 'not_permitted');
        }
        $order->update(['status' => $status]);
        Audit::record('sales.order_status', "Order {$order->number} is now {$status}", $order);

        return $order;
    }

    /**
     * Issues the invoice (stock leaves, sale recognised). Delivery orders get a
     * delivery record with the cash to collect; pickup orders are completed.
     */
    public function dispatch(User $user, SalesOrder $order, ?int $riderId = null): SalesInvoice
    {
        if (! $user->hasPermission('sales.invoices.post', $order->store_id) && ! $user->hasPermission('sales.deliveries.manage', $order->store_id)) {
            throw new BusinessRuleException('You are not allowed to dispatch orders.', 'not_permitted');
        }

        return DB::transaction(function () use ($user, $order, $riderId) {
            $order = SalesOrder::query()->with('lines.variant.product', 'lines.variantUnit.unit', 'customer.customerProfile', 'store')->lockForUpdate()->findOrFail($order->id);
            if (! in_array($order->status, ['confirmed', 'picking', 'packed'], true)) {
                throw new BusinessRuleException("Order {$order->number} cannot be dispatched while {$order->status}.", 'invalid_status');
            }
            $store = $order->store;
            $date = $store->businessDate();
            $lines = [];
            foreach ($order->lines as $line) {
                $remaining = Decimal::sub($line->base_quantity, Decimal::add($line->base_quantity_invoiced, $line->base_quantity_cancelled));
                if (! Decimal::isPositive($remaining)) {
                    continue;
                }
                $quantity = Decimal::div($remaining, $line->unit_factor, 3);
                $lines[] = new SaleLine(
                    variant: $line->variant, unit: $line->variantUnit, quantity: $quantity, baseQuantity: $remaining,
                    listPrice: $line->unit_price, unitPrice: $line->unit_price, taxRate: $line->tax_rate, taxInclusive: $line->tax_inclusive,
                    discount: Decimal::isPositive($line->discount_amount) ? ['type' => 'amount', 'value' => $line->discount_amount] : null,
                    salesOrderLineId: $line->id,
                    priceRuleId: $line->price_rule_id,
                );
                $line->update(['base_quantity_invoiced' => Decimal::qty(Decimal::add($line->base_quantity_invoiced, $remaining))]);
            }
            $terms = $order->customer->customerProfile?->payment_terms_days ?: (int) Settings::get('sales.default_credit_days');
            $invoice = $this->recorder->record(new SaleDraft(
                store: $store, kind: 'invoice', channel: $order->channel, user: $user, lines: $lines, payments: [],
                businessDate: $date, customer: $order->customer, priceBookId: $order->price_book_id, salesOrder: $order,
                salesAgentId: $order->sales_agent_id, deliveryCharge: $order->delivery_charge,
                dueDate: $order->payment_preference === 'khata' ? CarbonImmutable::parse($date)->addDays($terms)->toDateString() : $date,
                notes: "Order {$order->number}",
            ));

            if ($order->fulfillment_type === 'delivery') {
                $delivery = Delivery::create([
                    'store_id' => $store->id,
                    'number' => Sequences::next('delivery', $store->id, $store->code),
                    'sales_order_id' => $order->id,
                    'sales_invoice_id' => $invoice->id,
                    'rider_id' => $riderId,
                    'status' => $riderId ? 'out_for_delivery' : 'pending',
                    'cod_amount' => $order->payment_preference === 'khata' ? '0.00' : $invoice->total,
                    'assigned_at' => $riderId ? now() : null,
                    'dispatched_at' => $riderId ? now() : null,
                ]);
                DeliveryEvent::create(['delivery_id' => $delivery->id, 'status' => $delivery->status, 'user_id' => $user->id]);
                $order->update(['status' => 'dispatched']);
            } else {
                $order->update(['status' => 'completed']);
            }
            Audit::record('sales.order_invoiced', "Order {$order->number} invoiced as {$invoice->number}", $order);

            return $invoice;
        });
    }

    public function cancel(?User $user, SalesOrder $order, int $reasonId): SalesOrder
    {
        return DB::transaction(function () use ($user, $order, $reasonId) {
            $order = SalesOrder::query()->lockForUpdate()->findOrFail($order->id);
            if (! in_array($order->status, ['draft', 'confirmed', 'picking', 'packed'], true)) {
                throw new BusinessRuleException('An invoiced order cannot be cancelled; issue a return (credit note) instead.', 'invalid_status');
            }
            if ($user && ! $user->hasPermission('sales.orders.confirm', $order->store_id)) {
                throw new BusinessRuleException('You are not allowed to cancel orders.', 'not_permitted');
            }
            ReasonCode::query()->where('type', 'order_cancel')->findOrFail($reasonId);
            StockReservation::query()->where('source_type', $order->getMorphClass())->where('source_id', $order->id)->where('status', 'active')->update(['status' => 'released']);
            $order->update(['status' => 'cancelled', 'cancel_reason_id' => $reasonId, 'cancelled_at' => now()]);
            Audit::record('sales.order_cancelled', "Cancelled order {$order->number}", $order);

            return $order;
        });
    }

    /** @return array<int, string> stock-holding variant id => base quantity (bundles expand to components) */
    private function stockParts(ProductVariant $variant, string $baseQuantity): array
    {
        if (! $variant->product->isStocked()) {
            return [];
        }
        if (! $variant->isBundle()) {
            return [$variant->id => $baseQuantity];
        }
        $parts = [];
        foreach (BundleComponent::query()->where('bundle_variant_id', $variant->id)->get() as $component) {
            $parts[$component->component_variant_id] = Decimal::mul($component->quantity, $baseQuantity);
        }

        return $parts;
    }
}
