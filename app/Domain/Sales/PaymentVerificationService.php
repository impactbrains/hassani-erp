<?php

namespace App\Domain\Sales;

use App\Domain\Accounting\JournalPoster;
use App\Exceptions\BusinessRuleException;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Support\Audit;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;

/**
 * Reconciles card, wallet and bank receipts against the actual statement.
 * Verifying confirms the money arrived. Rejecting means it never did: the
 * allocation is undone so the invoice is owed again, and the wallet balance
 * is reversed (to the customer's Khata, or to cash over/short for walk-ins).
 */
final class PaymentVerificationService
{
    public function __construct(private readonly JournalPoster $journals)
    {
    }

    public function verify(User $user, Payment $payment): Payment
    {
        $this->authorize($user, $payment);
        if ($payment->verification_status !== 'pending') {
            throw new BusinessRuleException('This payment is not waiting for verification.', 'not_pending');
        }
        $payment->update(['verification_status' => 'verified', 'verified_by' => $user->id, 'verified_at' => now()]);
        Audit::record('payment.verified', "Verified {$payment->method->name} payment {$payment->number} (ref {$payment->reference})", $payment);

        return $payment;
    }

    public function reject(User $user, Payment $payment, string $reason): Payment
    {
        $this->authorize($user, $payment);

        return DB::transaction(function () use ($user, $payment, $reason) {
            $payment = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            if ($payment->verification_status !== 'pending') {
                throw new BusinessRuleException('This payment is not waiting for verification.', 'not_pending');
            }
            foreach (PaymentAllocation::query()->where('payment_id', $payment->id)->get() as $allocation) {
                if ($allocation->allocatable_type === (new SalesInvoice)->getMorphClass()) {
                    $invoice = SalesInvoice::query()->lockForUpdate()->find($allocation->allocatable_id);
                    $invoice->amount_paid = Decimal::money(Decimal::sub($invoice->amount_paid, $allocation->amount));
                    $invoice->payment_status = Decimal::isZero($invoice->amount_paid) && Decimal::isZero($invoice->amount_credited) ? 'unpaid' : (Decimal::lte($invoice->balanceDue(), '0') ? 'paid' : 'partial');
                    $invoice->save();
                }
            }
            $counter = $payment->contact_id ? 'accounts_receivable' : 'cash_over_short';
            $this->journals->post('payment_rejected', $payment, $payment->store->businessDate(), "Rejected {$payment->method->name} payment {$payment->number}: {$reason}", [
                ['account' => $counter, 'debit' => $payment->amount, 'contact_id' => $payment->contact_id],
                ['account' => $payment->method->account_id, 'credit' => $payment->amount],
            ], $payment->store_id);
            $payment->update(['verification_status' => 'rejected', 'verified_by' => $user->id, 'verified_at' => now(), 'notes' => trim(($payment->notes ?? '')."\nRejected: {$reason}")]);
            Audit::record('payment.rejected', "Rejected payment {$payment->number}: {$reason}", $payment);

            return $payment;
        });
    }

    private function authorize(User $user, Payment $payment): void
    {
        if (! $user->hasPermission('sales.payments.verify', $payment->store_id)) {
            throw new BusinessRuleException('You are not allowed to verify payments.', 'not_permitted');
        }
    }
}
