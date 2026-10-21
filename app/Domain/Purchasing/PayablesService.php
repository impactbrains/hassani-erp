<?php

namespace App\Domain\Purchasing;

use App\Domain\Accounting\JournalPoster;
use App\Domain\Inventory\MovementTypes;
use App\Domain\Inventory\StockChange;
use App\Domain\Inventory\StockContext;
use App\Domain\Inventory\StockLedger;
use App\Domain\Sales\PaymentRecorder;
use App\Exceptions\BusinessRuleException;
use App\Models\Contact;
use App\Models\GoodsReceiptLine;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Shift;
use App\Models\Store;
use App\Models\SupplierBill;
use App\Models\SupplierBillLine;
use App\Models\SupplierReturn;
use App\Models\SupplierReturnLine;
use App\Models\User;
use App\Support\Audit;
use App\Support\Decimal;
use App\Support\Sequences;
use Illuminate\Support\Facades\DB;

/**
 * Supplier bills (3-way matched to receipts), supplier payments and returns
 * to suppliers.
 *
 *  Bill:     Dr GRNI (received value of billed goods), Dr purchase price
 *            variance (difference), Dr input tax, Dr expense lines; Cr AP.
 *  Payment:  Dr AP, Cr cash/bank/wallet.
 *  Return:   stock out at its current cost; Dr AP (if billed) or GRNI
 *            (if not yet billed) at the receipt cost; difference to PPV.
 */
final class PayablesService
{
    public function __construct(
        private readonly JournalPoster $journals,
        private readonly PaymentRecorder $payments,
        private readonly StockLedger $ledger,
    ) {
    }

    /**
     * @param  array{supplier_id: int, supplier_invoice_no?: string|null, bill_date: string, due_date?: string|null, notes?: string|null,
     *     lines: list<array{goods_receipt_line_id?: int|null, account_id?: int|null, description?: string|null, quantity: string, unit_price: string, tax_amount?: string|null}>}  $data
     */
    public function postBill(User $user, Store $store, array $data): SupplierBill
    {
        $this->authorize($user, 'purchasing.bills.manage', $store->id);

        return $this->recordBill($user, $store, $data);
    }

    /**
     * Posts a bill without checking the bills permission. Only for callers that checked their own
     * permission first (Purchase stock bills the goods it has just received).
     *
     * @param  array<string, mixed>  $data  same shape as postBill()
     */
    public function recordBill(User $user, Store $store, array $data): SupplierBill
    {
        $supplier = Contact::query()->ofType('supplier')->findOrFail($data['supplier_id']);

        return DB::transaction(function () use ($user, $store, $data, $supplier) {
            $bill = SupplierBill::create([
                'store_id' => $store->id,
                'number' => Sequences::next('supplier_bill', $store->id, $store->code),
                'supplier_id' => $supplier->id,
                'supplier_invoice_no' => $data['supplier_invoice_no'] ?? null,
                'bill_date' => $data['bill_date'],
                'due_date' => $data['due_date'] ?? $data['bill_date'],
                'status' => 'draft',
                'notes' => $data['notes'] ?? null,
                'created_by' => $user->id,
            ]);
            $journal = [];
            $subtotal = '0';
            $taxTotal = '0';
            foreach ($data['lines'] as $row) {
                $quantity = Decimal::qty($row['quantity']);
                $amount = Decimal::money(Decimal::mul($quantity, $row['unit_price']));
                $tax = Decimal::money($row['tax_amount'] ?? '0');
                $lineData = ['supplier_bill_id' => $bill->id, 'quantity' => $quantity, 'unit_price' => Decimal::money($row['unit_price']), 'tax_amount' => $tax, 'line_total' => Decimal::money(Decimal::add($amount, $tax))];

                if (! empty($row['goods_receipt_line_id'])) {
                    $receiptLine = GoodsReceiptLine::query()->with('receipt', 'variant.product')->lockForUpdate()->findOrFail($row['goods_receipt_line_id']);
                    if ($receiptLine->receipt->supplier_id !== $supplier->id || $receiptLine->receipt->status !== 'posted') {
                        throw new BusinessRuleException('That receipt line is not from this supplier.', 'receipt_mismatch');
                    }
                    $baseQuantity = Decimal::qty(Decimal::mul($quantity, $receiptLine->unit_factor));
                    $receivedBase = Decimal::mul($receiptLine->quantity_received, $receiptLine->unit_factor);
                    $unbilled = Decimal::sub($receivedBase, $receiptLine->base_quantity_billed);
                    if (Decimal::gt($baseQuantity, $unbilled)) {
                        throw new BusinessRuleException("Billing more {$receiptLine->variant->displayName()} than was received and not yet billed.", 'over_billed');
                    }
                    // GRNI was credited with goods value at the receipt price.
                    $receivedValue = Decimal::money(Decimal::mul($quantity, $receiptLine->unit_price));
                    $journal[] = ['account' => 'grni', 'debit' => $receivedValue, 'contact_id' => $supplier->id];
                    $journal[] = ['account' => 'purchase_price_variance', 'debit' => Decimal::sub($amount, $receivedValue), 'memo' => $receiptLine->variant->sku];
                    $receiptLine->update(['base_quantity_billed' => Decimal::qty(Decimal::add($receiptLine->base_quantity_billed, $baseQuantity))]);
                    SupplierBillLine::create($lineData + ['goods_receipt_line_id' => $receiptLine->id, 'variant_id' => $receiptLine->variant_id, 'description' => $receiptLine->variant->displayName(), 'base_quantity' => $baseQuantity]);
                } else {
                    if (empty($row['account_id'])) {
                        throw new BusinessRuleException('Stock must be billed against a goods receipt; other lines need an expense account.', 'account_required');
                    }
                    $journal[] = ['account' => (int) $row['account_id'], 'debit' => $amount];
                    SupplierBillLine::create($lineData + ['account_id' => $row['account_id'], 'description' => $row['description'] ?? 'Expense']);
                }
                $journal[] = ['account' => 'input_tax', 'debit' => $tax];
                $subtotal = Decimal::add($subtotal, $amount);
                $taxTotal = Decimal::add($taxTotal, $tax);
            }
            $total = Decimal::money(Decimal::add($subtotal, $taxTotal));
            if (! Decimal::isPositive($total)) {
                throw new BusinessRuleException('The bill total must be more than zero.', 'empty_bill');
            }
            $journal[] = ['account' => 'accounts_payable', 'credit' => $total, 'contact_id' => $supplier->id];

            $bill->update(['subtotal' => Decimal::money($subtotal), 'tax_total' => Decimal::money($taxTotal), 'total' => $total, 'status' => 'posted', 'posted_by' => $user->id, 'posted_at' => now()]);
            $this->journals->post('supplier_bill', $bill, $bill->bill_date->toDateString(), "Supplier bill {$bill->number} ({$supplier->name})", $journal, $store->id);
            Audit::record('purchasing.bill_posted', "Posted bill {$bill->number} from {$supplier->name} for Rs {$total}", $bill);

            return $bill->fresh('lines');
        });
    }

    /** @param array{supplier_id: int, payment_method_id: int, amount: string, reference?: string|null, shift_id?: int|null, bill_ids?: list<int>, idempotency_key?: string|null, notes?: string|null} $data */
    public function paySupplier(User $user, Store $store, array $data): Payment
    {
        $this->authorize($user, 'purchasing.payments.make', $store->id);

        return $this->recordPayment($user, $store, $data);
    }

    /**
     * Pays a supplier without checking the payments permission. Only for callers that checked their
     * own permission first (Purchase stock paid on the spot).
     *
     * @param  array<string, mixed>  $data  same shape as paySupplier()
     */
    public function recordPayment(User $user, Store $store, array $data): Payment
    {
        if (! empty($data['idempotency_key']) && ($existing = Payment::query()->where('idempotency_key', $data['idempotency_key'])->first())) {
            return $existing;
        }
        $supplier = Contact::query()->ofType('supplier')->findOrFail($data['supplier_id']);
        $method = PaymentMethod::query()->where('is_active', true)->findOrFail($data['payment_method_id']);
        $amount = Decimal::money($data['amount']);
        if (! Decimal::isPositive($amount)) {
            throw new BusinessRuleException('Enter an amount.', 'amount_required');
        }

        return DB::transaction(function () use ($user, $store, $data, $supplier, $method, $amount) {
            $shift = null;
            if ($method->type === 'cash' && ! empty($data['shift_id'])) {
                $shift = Shift::query()->where('status', 'open')->where('store_id', $store->id)->findOrFail($data['shift_id']);
            }
            $bills = SupplierBill::query()->where('supplier_id', $supplier->id)->where('status', 'posted')->whereIn('payment_status', ['unpaid', 'partial'])
                ->when(! empty($data['bill_ids']), fn ($q) => $q->whereIn('id', $data['bill_ids']))
                ->orderBy('due_date')->orderBy('id')->lockForUpdate()->get();
            $allocations = [];
            $remaining = $amount;
            foreach ($bills as $bill) {
                if (! Decimal::isPositive($remaining)) {
                    break;
                }
                $due = Decimal::sub($bill->total, Decimal::add($bill->amount_paid, $bill->amount_credited));
                $apply = Decimal::min($remaining, $due);
                if (! Decimal::isPositive($apply)) {
                    continue;
                }
                $bill->amount_paid = Decimal::money(Decimal::add($bill->amount_paid, $apply));
                $bill->payment_status = Decimal::gte(Decimal::add($bill->amount_paid, $bill->amount_credited), $bill->total) ? 'paid' : 'partial';
                $bill->save();
                $allocations[] = [$bill, $apply];
                $remaining = Decimal::sub($remaining, $apply);
            }

            $payment = $this->payments->record($method, $amount, [
                'store_id' => $store->id, 'direction' => 'out', 'kind' => 'supplier_payment', 'contact_id' => $supplier->id,
                'reference' => $data['reference'] ?? null, 'shift_id' => $shift?->id, 'register_id' => $shift?->register_id,
                'custody' => $method->type === 'cash' ? ($shift ? 'drawer' : 'cash') : $method->type,
                'received_by' => $user->id, 'business_date' => $store->businessDate(),
                'idempotency_key' => $data['idempotency_key'] ?? null, 'notes' => $data['notes'] ?? null,
            ], $allocations);
            // Supplier payments leave the business; they are not "pending verification" receipts.
            $payment->update(['verification_status' => 'not_required']);

            $this->journals->post('supplier_payment', $payment, $payment->business_date->toDateString(), "Payment {$payment->number} to {$supplier->name}", [
                ['account' => 'accounts_payable', 'debit' => $amount, 'contact_id' => $supplier->id],
                ['account' => $method->account_id, 'credit' => $amount],
            ], $store->id);
            Audit::record('purchasing.supplier_paid', "Paid {$supplier->name} Rs {$amount} by {$method->name}", $payment);

            return $payment;
        });
    }

    /** @param array{supplier_id: int, goods_receipt_id?: int|null, reason_code_id?: int|null, notes?: string|null, lines: list<array{goods_receipt_line_id: int, location_id: int, base_quantity: string}>} $data */
    public function returnToSupplier(User $user, Store $store, array $data): SupplierReturn
    {
        $this->authorize($user, 'purchasing.returns.manage', $store->id);
        $supplier = Contact::query()->ofType('supplier')->findOrFail($data['supplier_id']);

        return DB::transaction(function () use ($user, $store, $data, $supplier) {
            $return = SupplierReturn::create([
                'store_id' => $store->id, 'number' => Sequences::next('supplier_return', $store->id, $store->code),
                'supplier_id' => $supplier->id, 'goods_receipt_id' => $data['goods_receipt_id'] ?? null,
                'reason_code_id' => $data['reason_code_id'] ?? null, 'status' => 'draft', 'notes' => $data['notes'] ?? null, 'created_by' => $user->id,
            ]);
            $date = $store->businessDate();
            $context = new StockContext($return->getMorphClass(), $return->id, $user->id, $date);
            $journal = [];
            $total = '0';
            foreach ($data['lines'] as $row) {
                $receiptLine = GoodsReceiptLine::query()->with('receipt')->lockForUpdate()->findOrFail($row['goods_receipt_line_id']);
                if ($receiptLine->receipt->supplier_id !== $supplier->id) {
                    throw new BusinessRuleException('That receipt line is from another supplier.', 'receipt_mismatch');
                }
                $quantity = Decimal::qty($row['base_quantity']);
                $returnable = Decimal::sub(Decimal::mul($receiptLine->quantity_received, $receiptLine->unit_factor), $receiptLine->base_quantity_returned);
                if (! Decimal::isPositive($quantity) || Decimal::gt($quantity, $returnable)) {
                    throw new BusinessRuleException('You can only return what was received and not already returned.', 'return_exceeds_received');
                }
                $line = SupplierReturnLine::create([
                    'supplier_return_id' => $return->id, 'goods_receipt_line_id' => $receiptLine->id, 'variant_id' => $receiptLine->variant_id,
                    'location_id' => $row['location_id'], 'batch_id' => $receiptLine->batch_id, 'base_quantity' => $quantity,
                    'unit_cost' => $receiptLine->base_unit_cost, 'line_total' => Decimal::money(Decimal::mul($quantity, $receiptLine->base_unit_cost)),
                ]);
                [$movement] = $this->ledger->post([new StockChange($store->id, (int) $row['location_id'], $receiptLine->variant_id, Decimal::neg($quantity), MovementTypes::PURCHASE_RETURN, $receiptLine->batch_id, sourceLineId: $line->id)], $context);
                $stockValue = Decimal::abs($movement->total_cost ?? '0');

                $billedNotReturned = Decimal::sub($receiptLine->base_quantity_billed, $receiptLine->base_quantity_returned);
                $settleAccount = Decimal::isPositive($billedNotReturned) ? 'accounts_payable' : 'grni';
                $returnValue = Decimal::money(Decimal::div(Decimal::mul($quantity, $receiptLine->unit_price), $receiptLine->unit_factor, 6));
                $journal[] = ['account' => $settleAccount, 'debit' => $returnValue, 'contact_id' => $supplier->id];
                $journal[] = ['account' => 'inventory', 'credit' => $stockValue];
                $journal[] = ['account' => 'purchase_price_variance', 'credit' => Decimal::sub($returnValue, $stockValue)];
                $receiptLine->update(['base_quantity_returned' => Decimal::qty(Decimal::add($receiptLine->base_quantity_returned, $quantity))]);
                $total = Decimal::add($total, $returnValue);
            }
            $return->update(['status' => 'posted', 'total' => Decimal::money($total), 'posted_by' => $user->id, 'posted_at' => now(), 'business_date' => $date]);
            $this->journals->post('supplier_return', $return, $date, "Return {$return->number} to {$supplier->name}", $journal, $store->id);
            $this->applyDebitToBills($supplier->id, Decimal::money($total));
            Audit::record('purchasing.supplier_return', "Returned goods worth Rs {$total} to {$supplier->name} ({$return->number})", $return);

            return $return->fresh('lines');
        });
    }

    /** Reduces what is owed on open bills by a debit note amount (oldest first). */
    private function applyDebitToBills(int $supplierId, string $amount): void
    {
        $remaining = $amount;
        foreach (SupplierBill::query()->where('supplier_id', $supplierId)->whereIn('payment_status', ['unpaid', 'partial'])->orderBy('due_date')->lockForUpdate()->get() as $bill) {
            if (! Decimal::isPositive($remaining)) {
                break;
            }
            $due = Decimal::sub($bill->total, Decimal::add($bill->amount_paid, $bill->amount_credited));
            $apply = Decimal::min($remaining, $due);
            $bill->amount_credited = Decimal::money(Decimal::add($bill->amount_credited, $apply));
            $bill->payment_status = Decimal::gte(Decimal::add($bill->amount_paid, $bill->amount_credited), $bill->total) ? 'paid' : 'partial';
            $bill->save();
            $remaining = Decimal::sub($remaining, $apply);
        }
    }

    private function authorize(User $user, string $permission, int $storeId): void
    {
        if (! $user->hasPermission($permission, $storeId)) {
            throw new BusinessRuleException('You are not allowed to do this.', 'not_permitted');
        }
    }
}
