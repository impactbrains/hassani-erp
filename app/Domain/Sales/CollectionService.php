<?php

namespace App\Domain\Sales;

use App\Domain\Accounting\JournalPoster;
use App\Exceptions\BusinessRuleException;
use App\Models\Contact;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\PaymentPromise;
use App\Models\SalesInvoice;
use App\Models\Shift;
use App\Models\Store;
use App\Models\User;
use App\Support\Audit;
use App\Support\Decimal;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Wasooli: money received against a customer's Khata. Applied to the oldest
 * open invoices first (or to the invoices chosen); anything left over stays
 * as a credit balance on the account. A promise to pay counts as kept only
 * when the money is actually received.
 */
final class CollectionService
{
    public function __construct(private readonly PaymentRecorder $payments, private readonly JournalPoster $journals)
    {
    }

    /** @param array{customer_id: int, payment_method_id: int, amount: string, reference?: string|null, shift_id?: int|null, invoice_ids?: list<int>, idempotency_key?: string|null, notes?: string|null} $data */
    public function receive(User $user, Store $store, array $data): Payment
    {
        if (! $user->hasPermission('sales.payments.receive', $store->id)) {
            throw new BusinessRuleException('You are not allowed to receive payments.', 'not_permitted');
        }
        if (! empty($data['idempotency_key']) && ($existing = Payment::query()->where('idempotency_key', $data['idempotency_key'])->first())) {
            return $existing;
        }
        $customer = Contact::query()->customers()->findOrFail($data['customer_id']);
        $method = PaymentMethod::query()->where('is_active', true)->findOrFail($data['payment_method_id']);
        $amount = Decimal::money($data['amount']);
        if (! Decimal::isPositive($amount)) {
            throw new BusinessRuleException('Enter the amount received.', 'amount_required', field: 'amount');
        }
        if ($method->requires_reference && empty($data['reference'])) {
            throw new BusinessRuleException("Enter the {$method->name} transaction reference.", 'reference_required', field: 'reference');
        }

        try {
            return DB::transaction(function () use ($user, $store, $data, $customer, $method, $amount) {
                $shift = null;
                if ($method->type === 'cash') {
                    $shift = isset($data['shift_id']) ? Shift::query()->where('status', 'open')->where('store_id', $store->id)->find($data['shift_id']) : null;
                }
                $invoices = SalesInvoice::query()
                    ->where('customer_id', $customer->id)->where('status', 'posted')->whereIn('payment_status', ['unpaid', 'partial'])
                    ->when(! empty($data['invoice_ids']), fn ($q) => $q->whereIn('id', $data['invoice_ids']))
                    ->orderBy('due_date')->orderBy('id')->lockForUpdate()->get();

                $remaining = $amount;
                $allocations = [];
                foreach ($invoices as $invoice) {
                    if (! Decimal::isPositive($remaining)) {
                        break;
                    }
                    $apply = Decimal::min($remaining, $invoice->balanceDue());
                    if (! Decimal::isPositive($apply)) {
                        continue;
                    }
                    $invoice->amount_paid = Decimal::money(Decimal::add($invoice->amount_paid, $apply));
                    $invoice->payment_status = Decimal::lte($invoice->balanceDue(), '0') ? 'paid' : 'partial';
                    $invoice->save();
                    $allocations[] = [$invoice, $apply];
                    $remaining = Decimal::sub($remaining, $apply);
                }

                $payment = $this->payments->record($method, $amount, [
                    'store_id' => $store->id, 'direction' => 'in', 'kind' => 'collection', 'contact_id' => $customer->id,
                    'reference' => $data['reference'] ?? null, 'shift_id' => $shift?->id, 'register_id' => $shift?->register_id,
                    'custody' => $method->type === 'cash' ? ($shift ? 'drawer' : 'safe') : $method->type,
                    'received_by' => $user->id, 'business_date' => $store->businessDate(),
                    'idempotency_key' => $data['idempotency_key'] ?? null, 'notes' => $data['notes'] ?? null,
                ], $allocations);

                $this->journals->post('collection', $payment, $payment->business_date->toDateString(), "Wasooli {$payment->number} from {$customer->name}", [
                    ['account' => $method->account_id, 'debit' => $amount, 'contact_id' => $customer->id],
                    ['account' => 'accounts_receivable', 'credit' => $amount, 'contact_id' => $customer->id],
                ], $store->id);

                $this->settlePromises($customer->id, $amount);
                Audit::record('sales.collection', "Received Rs {$amount} from {$customer->name} by {$method->name}", $payment);

                return $payment;
            });
        } catch (UniqueConstraintViolationException $e) {
            if (! empty($data['idempotency_key']) && ($existing = Payment::query()->where('idempotency_key', $data['idempotency_key'])->first())) {
                return $existing;
            }
            throw $e;
        }
    }

    private function settlePromises(int $customerId, string $amount): void
    {
        $remaining = $amount;
        foreach (PaymentPromise::query()->where('contact_id', $customerId)->where('status', 'pending')->orderBy('promised_date')->get() as $promise) {
            if (Decimal::lt($remaining, $promise->amount)) {
                break;
            }
            $promise->update(['status' => $promise->promised_date->isPast() && ! $promise->promised_date->isToday() ? 'kept_late' : 'kept', 'resolved_at' => now()]);
            $remaining = Decimal::sub($remaining, $promise->amount);
        }
    }
}
