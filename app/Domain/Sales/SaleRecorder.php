<?php

namespace App\Domain\Sales;

use App\Domain\Accounting\JournalPoster;
use App\Domain\Inventory\Availability;
use App\Domain\Inventory\MovementTypes;
use App\Domain\Inventory\SaleAllocator;
use App\Domain\Inventory\StockChange;
use App\Domain\Inventory\StockContext;
use App\Domain\Inventory\StockLedger;
use App\Exceptions\BusinessRuleException;
use App\Models\BundleComponent;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceLine;
use App\Models\StockReservation;
use App\Support\Decimal;
use App\Support\Sequences;
use Illuminate\Support\Facades\DB;

/**
 * Posts a sale in one transaction:
 *   invoice + lines  ->  stock out (FEFO, bundles, reservations)  ->
 *   payments + allocations  ->  sale journal + cost-of-sales journal.
 *
 * Callers (POS checkout, order invoicing, API) do the validation that is
 * specific to them (shift, approvals, idempotency) before calling record().
 */
final class SaleRecorder
{
    public function __construct(
        private readonly CartPricer $pricer,
        private readonly StockLedger $ledger,
        private readonly SaleAllocator $allocator,
        private readonly Availability $availability,
        private readonly PaymentRecorder $payments,
        private readonly JournalPoster $journals,
    ) {
    }

    public function record(SaleDraft $draft): SalesInvoice
    {
        return DB::transaction(function () use ($draft) {
            $this->lockStockInVariantOrder($draft);
            $pricing = $this->pricer->price(
                array_map(fn (SaleLine $line, int $i) => $line->forPricer($i), $draft->lines, array_keys($draft->lines)),
                $draft->cartDiscount,
                $draft->roundingStep,
            );
            $deliveryCharge = Decimal::money($draft->deliveryCharge);
            $total = Decimal::money(Decimal::add($pricing['total'], $deliveryCharge));
            $paid = Decimal::money(Decimal::sum(array_map(fn (SalePayment $p) => $p->amount, $draft->payments)));
            if (Decimal::gt($paid, $total)) {
                throw new BusinessRuleException('Payments are more than the bill total. Give change on the cash payment instead.', 'overpayment');
            }
            $credit = Decimal::money(Decimal::sub($total, $paid));
            if (Decimal::isPositive($credit) && $draft->customer === null) {
                throw new BusinessRuleException('The bill is not fully paid. Choose a customer to put the rest on Khata, or take full payment.', 'payment_incomplete');
            }

            $invoice = SalesInvoice::create([
                'store_id' => $draft->store->id,
                'number' => Sequences::next($draft->kind === 'pos' ? 'pos_receipt' : 'invoice', $draft->store->id, $draft->store->code),
                'kind' => $draft->kind,
                'status' => 'posted',
                'channel' => $draft->channel,
                'customer_id' => $draft->customer?->id,
                'customer_name' => $draft->customer?->name,
                'customer_phone' => $draft->customer?->phone,
                'price_book_id' => $draft->priceBookId,
                'sales_order_id' => $draft->salesOrder?->id,
                'register_id' => $draft->register?->id,
                'shift_id' => $draft->shift?->id,
                'cashier_id' => $draft->user->id,
                'sales_agent_id' => $draft->salesAgentId,
                'subtotal' => $pricing['subtotal'],
                'discount_total' => $pricing['discount_total'],
                'tax_total' => $pricing['tax_total'],
                'delivery_charge' => $deliveryCharge,
                'rounding_adjustment' => $pricing['rounding_adjustment'],
                'total' => $total,
                'amount_paid' => $paid,
                'credit_extended' => $credit,
                'payment_status' => Decimal::isZero($credit) ? 'paid' : (Decimal::isPositive($paid) ? 'partial' : 'unpaid'),
                'due_date' => Decimal::isPositive($credit) ? $draft->dueDate : null,
                'business_date' => $draft->businessDate,
                'posted_at' => now(),
                'posted_by' => $draft->user->id,
                'idempotency_key' => $draft->idempotencyKey,
                'notes' => $draft->notes,
            ]);

            $context = new StockContext($invoice->getMorphClass(), $invoice->id, $draft->user->id, $draft->businessDate);
            $costTotal = '0';
            $costComplete = true;
            foreach ($draft->lines as $index => $line) {
                $priced = $pricing['lines'][$index];
                $invoiceLine = SalesInvoiceLine::create([
                    'sales_invoice_id' => $invoice->id,
                    'sales_order_line_id' => $line->salesOrderLineId,
                    'variant_id' => $line->variant->id,
                    'variant_unit_id' => $line->unit->id,
                    'description' => $this->lineDescription($line),
                    'description_ur' => $line->description !== null ? null : $line->variant->displayNameUr(),
                    'quantity' => Decimal::qty($line->quantity),
                    'unit_factor' => $line->unit->factor,
                    'base_quantity' => Decimal::qty($line->baseQuantity),
                    'list_price' => Decimal::money($line->listPrice),
                    'unit_price' => Decimal::money($line->unitPrice),
                    'mrp' => $line->variant->mrp,
                    'price_overridden' => $line->priceOverridden(),
                    'line_discount' => $priced['line_discount'],
                    'cart_discount_share' => $priced['cart_discount_share'],
                    'discount_reason_id' => $line->discountReasonId ?? (Decimal::isPositive($priced['cart_discount_share']) ? $draft->cartDiscountReasonId : null),
                    'tax_rate' => $line->taxRate,
                    'tax_inclusive' => $line->taxInclusive,
                    'tax_amount' => $priced['tax_amount'],
                    'revenue_amount' => $priced['revenue_amount'],
                    'line_total' => $priced['line_total'],
                    'approval_id' => $line->approvalId,
                ]);

                $lineCost = $this->takeStock($draft, $line, $invoiceLine, $context);
                if ($lineCost === null) {
                    $costComplete = $costComplete && ! $line->variant->product->isStocked();
                } else {
                    $invoiceLine->cost_amount = $lineCost['cost'];
                    $invoiceLine->save();
                    $costTotal = Decimal::add($costTotal, $lineCost['cost']);
                    $costComplete = $costComplete && $lineCost['known'];
                }
            }
            $invoice->cost_total = Decimal::money($costTotal);
            $invoice->cost_complete = $costComplete;
            $invoice->save();

            $this->recordPayments($draft, $invoice);
            $this->postJournals($draft, $invoice, $pricing, $credit);

            return $invoice->load(['lines.variant.product', 'lines.variantUnit.unit', 'customer']);
        });
    }

    /** @return array{cost: string, known: bool}|null null when the product does not hold stock */
    private function takeStock(SaleDraft $draft, SaleLine $line, SalesInvoiceLine $invoiceLine, StockContext $context): ?array
    {
        $product = $line->variant->product;
        if (! $product->isStocked()) {
            return null;
        }

        $preferredLocation = $draft->register?->location_id;
        $components = $line->variant->isBundle()
            ? BundleComponent::query()->with('component.product')->where('bundle_variant_id', $line->variant->id)->get()
                ->map(fn ($c) => [$c->component, Decimal::qty(Decimal::mul($c->quantity, $line->baseQuantity)), MovementTypes::BUNDLE_CONSUME])->all()
            : [[$line->variant, $line->baseQuantity, MovementTypes::SALE]];
        if ($components === []) {
            throw new BusinessRuleException("Bundle {$line->variant->displayName()} has no components set up.", 'bundle_empty');
        }

        $cost = '0';
        $known = true;
        foreach ($components as [$variant, $quantity, $type]) {
            if ($draft->salesOrder) {
                $this->fulfilReservation($draft->salesOrder->id, $variant->id, $quantity);
            }
            $this->availability->assertAvailable($draft->store->id, $variant, $quantity, $draft->businessDate);
            $changes = [];
            foreach ($this->allocator->allocate($draft->store->id, $preferredLocation, $variant, $quantity, $draft->businessDate) as $allocation) {
                $changes[] = new StockChange(
                    $draft->store->id,
                    $allocation['location_id'],
                    $variant->id,
                    Decimal::neg($allocation['quantity']),
                    $type,
                    $allocation['batch_id'],
                    sourceLineId: $invoiceLine->id,
                );
            }
            foreach ($this->ledger->post($changes, $context) as $movement) {
                $cost = Decimal::add($cost, Decimal::abs($movement->total_cost ?? '0'));
                if ($movement->unit_cost === null || Decimal::isZero($movement->unit_cost)) {
                    $known = false;
                }
            }
        }

        return ['cost' => Decimal::money($cost), 'known' => $known];
    }

    /**
     * Locks every SKU this sale touches in ascending id order before any
     * stock moves, so two POS counters selling the same items in a different order
     * wait for each other instead of deadlocking.
     */
    private function lockStockInVariantOrder(SaleDraft $draft): void
    {
        $variantIds = [];
        foreach ($draft->lines as $line) {
            if (! $line->variant->product->isStocked()) {
                continue;
            }
            if ($line->variant->isBundle()) {
                foreach (BundleComponent::query()->where('bundle_variant_id', $line->variant->id)->pluck('component_variant_id') as $id) {
                    $variantIds[] = (int) $id;
                }
            } else {
                $variantIds[] = $line->variant->id;
            }
        }
        $variantIds = array_values(array_unique($variantIds));
        sort($variantIds);
        foreach ($variantIds as $variantId) {
            $this->availability->sellable($draft->store->id, $variantId, $draft->businessDate, lock: true);
        }
    }

    private function fulfilReservation(int $salesOrderId, int $variantId, string $quantity): void
    {
        $remaining = $quantity;
        $reservations = StockReservation::query()
            ->where('source_type', (new \App\Models\SalesOrder)->getMorphClass())
            ->where('source_id', $salesOrderId)
            ->where('variant_id', $variantId)
            ->where('status', 'active')
            ->lockForUpdate()
            ->get();
        foreach ($reservations as $reservation) {
            if (! Decimal::isPositive($remaining)) {
                break;
            }
            $take = Decimal::min($remaining, $reservation->quantity);
            $left = Decimal::sub($reservation->quantity, $take);
            $reservation->quantity = Decimal::qty($left);
            $reservation->status = Decimal::isPositive($left) ? 'active' : 'fulfilled';
            $reservation->save();
            $remaining = Decimal::sub($remaining, $take);
        }
    }

    private function recordPayments(SaleDraft $draft, SalesInvoice $invoice): void
    {
        foreach ($draft->payments as $index => $payment) {
            if (! Decimal::isPositive($payment->amount)) {
                continue;
            }
            $this->payments->record($payment->method, $payment->amount, [
                'store_id' => $draft->store->id,
                'direction' => 'in',
                'kind' => $draft->paymentCustody === 'rider' ? 'delivery_collection' : 'sale',
                'contact_id' => $draft->customer?->id,
                'tendered_amount' => $payment->tendered !== null ? Decimal::money($payment->tendered) : null,
                'change_amount' => Decimal::money($payment->change),
                'reference' => $payment->reference,
                'terminal_label' => $payment->terminalLabel,
                'custody' => $draft->paymentCustody,
                'rider_id' => $draft->riderId,
                'shift_id' => $draft->paymentCustody === 'drawer' ? $draft->shift?->id : null,
                'register_id' => $draft->register?->id,
                'received_by' => $draft->user->id,
                'business_date' => $draft->businessDate,
                'idempotency_key' => $draft->idempotencyKey ? $draft->idempotencyKey.':'.$index : null,
            ], [[$invoice, $payment->amount]]);
        }
    }

    /** @param array<string, mixed> $pricing */
    private function postJournals(SaleDraft $draft, SalesInvoice $invoice, array $pricing, string $credit): void
    {
        $contactId = $draft->customer?->id;
        $lines = [];
        foreach ($draft->payments as $payment) {
            $account = $draft->paymentCustody === 'rider' && $payment->method->type === 'cash' ? 'cash_with_riders' : $payment->method->account_id;
            $lines[] = ['account' => $account, 'debit' => $payment->amount, 'contact_id' => $contactId, 'memo' => $payment->method->name];
        }
        $lines[] = ['account' => 'accounts_receivable', 'debit' => $credit, 'contact_id' => $contactId, 'memo' => 'Khata'];
        $lines[] = ['account' => 'sales', 'credit' => Decimal::sum(array_column($pricing['lines'], 'revenue_amount'))];
        $lines[] = ['account' => 'sales_tax_payable', 'credit' => $pricing['tax_total']];
        $lines[] = ['account' => 'delivery_income', 'credit' => $invoice->delivery_charge];
        $lines[] = ['account' => 'rounding', 'credit' => $pricing['rounding_adjustment']];

        $this->journals->post('sale', $invoice, $draft->businessDate, "Sale {$invoice->number}", $lines, $draft->store->id);
        $this->journals->post('cogs', $invoice, $draft->businessDate, "Cost of sale {$invoice->number}", [
            ['account' => 'cogs', 'debit' => $invoice->cost_total],
            ['account' => 'inventory', 'credit' => $invoice->cost_total],
        ], $draft->store->id);
    }

    private function lineDescription(SaleLine $line): string
    {
        if ($line->description !== null) {
            return $line->description;
        }
        $name = $line->variant->displayName();
        $unit = $line->unit->unit;

        return $line->unit->is_base || $unit === null ? $name : $name.' ('.$unit->name.')';
    }
}
