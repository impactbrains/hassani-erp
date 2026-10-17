<?php

namespace App\Domain\Sales;

use App\Domain\Accounting\JournalPoster;
use App\Exceptions\BusinessRuleException;
use App\Models\CashMovement;
use App\Models\Contact;
use App\Models\Delivery;
use App\Models\DeliveryEvent;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\RiderHandover;
use App\Models\SalesInvoice;
use App\Models\Shift;
use App\Models\User;
use App\Support\Audit;
use App\Support\Decimal;
use App\Support\Sequences;
use Illuminate\Support\Facades\DB;

/**
 * Rider deliveries and cash-on-delivery.
 *
 * Cash a rider collects is the rider's custody ("cash with riders"), not
 * drawer cash, until it is handed over and counted. A handover moves it
 * into a shift's drawer (as a pay-in) or the safe; any difference is a
 * variance for review, never an automatic deduction from the rider.
 */
final class DeliveryService
{
    public function __construct(private readonly PaymentRecorder $payments, private readonly JournalPoster $journals)
    {
    }

    public function assign(User $user, Delivery $delivery, int $riderId): Delivery
    {
        $this->authorizeManager($user, $delivery->store_id);
        $rider = Contact::query()->employees()->findOrFail($riderId);
        if (! in_array($delivery->status, ['pending', 'assigned', 'failed'], true)) {
            throw new BusinessRuleException("Delivery {$delivery->number} is {$delivery->status}.", 'invalid_status');
        }
        $delivery->update(['rider_id' => $rider->id, 'status' => 'out_for_delivery', 'assigned_at' => now(), 'dispatched_at' => now()]);
        $this->event($delivery, 'out_for_delivery', $user, "Assigned to {$rider->name}");

        return $delivery;
    }

    /**
     * The rider (or a manager on their behalf) marks the order delivered and
     * records the cash collected.
     */
    public function markDelivered(User $user, Delivery $delivery, string $collected, ?string $note = null, ?string $idempotencyKey = null): Delivery
    {
        return DB::transaction(function () use ($user, $delivery, $collected, $note, $idempotencyKey) {
            $delivery = Delivery::query()->lockForUpdate()->findOrFail($delivery->id);
            $this->authorizeRiderOrManager($user, $delivery);
            if ($delivery->status === 'delivered') {
                return $delivery; // retried request
            }
            if ($delivery->status !== 'out_for_delivery') {
                throw new BusinessRuleException("Delivery {$delivery->number} is {$delivery->status}.", 'invalid_status');
            }
            $collected = Decimal::money($collected);
            $invoice = SalesInvoice::query()->lockForUpdate()->findOrFail($delivery->sales_invoice_id);
            if (Decimal::gt($collected, $invoice->balanceDue())) {
                throw new BusinessRuleException("Collected amount is more than the Rs {$invoice->balanceDue()} due.", 'overcollection');
            }
            if (Decimal::isPositive($collected)) {
                $cash = PaymentMethod::query()->where('code', 'cash')->firstOrFail();
                $payment = $this->payments->record($cash, $collected, [
                    'store_id' => $delivery->store_id, 'direction' => 'in', 'kind' => 'delivery_collection',
                    'contact_id' => $invoice->customer_id, 'custody' => 'rider', 'rider_id' => $delivery->rider_id,
                    'received_by' => $user->id, 'business_date' => $invoice->store->businessDate(),
                    'idempotency_key' => $idempotencyKey,
                ], [[$invoice, $collected]]);
                $invoice->amount_paid = Decimal::money(Decimal::add($invoice->amount_paid, $collected));
                $invoice->payment_status = Decimal::lte($invoice->balanceDue(), '0') ? 'paid' : 'partial';
                $invoice->save();
                $this->journals->post('delivery_collection', $payment, $payment->business_date->toDateString(), "COD {$delivery->number} collected by rider", [
                    ['account' => 'cash_with_riders', 'debit' => $collected, 'contact_id' => $delivery->rider_id],
                    ['account' => 'accounts_receivable', 'credit' => $collected, 'contact_id' => $invoice->customer_id],
                ], $delivery->store_id);
            }
            $delivery->update(['status' => 'delivered', 'collected_amount' => $collected, 'delivered_at' => now(), 'proof_note' => $note]);
            $delivery->salesOrder?->update(['status' => 'delivered']);
            $this->event($delivery, 'delivered', $user, $note);

            return $delivery;
        });
    }

    public function markFailed(User $user, Delivery $delivery, string $reason): Delivery
    {
        $this->authorizeRiderOrManager($user, $delivery);
        if ($delivery->status !== 'out_for_delivery') {
            throw new BusinessRuleException("Delivery {$delivery->number} is {$delivery->status}.", 'invalid_status');
        }
        $delivery->update(['status' => 'failed', 'failure_reason' => $reason, 'attempts' => $delivery->attempts + 1]);
        $this->event($delivery, 'failed', $user, $reason);

        return $delivery;
    }

    /** Goods came back to the shop; the caller issues the credit note for the invoice. */
    public function markReturned(User $user, Delivery $delivery, string $reason): Delivery
    {
        $this->authorizeManager($user, $delivery->store_id);
        if (! in_array($delivery->status, ['failed', 'out_for_delivery'], true)) {
            throw new BusinessRuleException("Delivery {$delivery->number} is {$delivery->status}.", 'invalid_status');
        }
        $delivery->update(['status' => 'returned', 'failure_reason' => $reason]);
        $this->event($delivery, 'returned', $user, $reason);

        return $delivery;
    }

    /** Cash collected by a rider that has not been handed over yet. */
    public function cashWithRider(int $riderId): string
    {
        return Decimal::money(Payment::query()->where('rider_id', $riderId)->where('custody', 'rider')->whereNull('rider_handover_id')->sum('amount'));
    }

    /** The rider hands over collected cash; it goes into an open shift's drawer when one is given. */
    public function handover(User $user, int $storeId, int $riderId, string $received, ?int $shiftId, ?string $note): RiderHandover
    {
        if (! $user->hasPermission('sales.riders.handover', $storeId)) {
            throw new BusinessRuleException('You are not allowed to receive rider cash.', 'not_permitted');
        }

        return DB::transaction(function () use ($user, $storeId, $riderId, $received, $shiftId, $note) {
            $payments = Payment::query()->where('store_id', $storeId)->where('rider_id', $riderId)->where('custody', 'rider')->whereNull('rider_handover_id')->lockForUpdate()->get();
            $expected = Decimal::money(Decimal::sum($payments->pluck('amount')));
            $received = Decimal::money($received);
            if ($payments->isEmpty() && Decimal::isZero($received)) {
                throw new BusinessRuleException('This rider has no collected cash to hand over.', 'nothing_to_handover');
            }
            $shift = $shiftId ? Shift::query()->where('status', 'open')->where('store_id', $storeId)->findOrFail($shiftId) : null;
            $store = \App\Models\Store::query()->findOrFail($storeId);
            $variance = Decimal::money(Decimal::sub($received, $expected));

            $handover = RiderHandover::create([
                'store_id' => $storeId, 'number' => Sequences::next('rider_handover', $storeId, $store->code), 'rider_id' => $riderId,
                'shift_id' => $shift?->id, 'expected_amount' => $expected, 'received_amount' => $received, 'variance' => $variance,
                'note' => $note, 'received_by' => $user->id, 'received_at' => now(), 'business_date' => $store->businessDate(),
            ]);
            Payment::query()->whereIn('id', $payments->pluck('id'))->update(['rider_handover_id' => $handover->id, 'custody' => $shift ? 'drawer' : 'safe']);
            if ($shift && Decimal::isPositive($received)) {
                CashMovement::create([
                    'store_id' => $storeId, 'shift_id' => $shift->id, 'number' => Sequences::next('cash_movement', $storeId, $store->code),
                    'type' => 'pay_in', 'amount' => $received, 'recipient' => 'Rider handover '.$handover->number, 'note' => $note,
                    'user_id' => $user->id, 'occurred_at' => now(), 'business_date' => $store->businessDate(),
                ]);
            }
            $this->journals->post('rider_handover', $handover, $handover->business_date->toDateString(), "Rider cash handover {$handover->number}", [
                ['account' => 'cash_on_hand', 'debit' => $received],
                ['account' => 'cash_with_riders', 'credit' => $expected, 'contact_id' => $riderId],
                ['account' => 'cash_over_short', 'credit' => $variance, 'memo' => 'Rider handover variance'],
            ], $storeId);
            Audit::record('sales.rider_handover', "Rider handover {$handover->number}: expected Rs {$expected}, received Rs {$received}", $handover);

            return $handover;
        });
    }

    private function event(Delivery $delivery, string $status, User $user, ?string $note): void
    {
        DeliveryEvent::create(['delivery_id' => $delivery->id, 'status' => $status, 'note' => $note, 'user_id' => $user->id]);
    }

    private function authorizeManager(User $user, int $storeId): void
    {
        if (! $user->hasPermission('sales.deliveries.manage', $storeId)) {
            throw new BusinessRuleException('You are not allowed to manage deliveries.', 'not_permitted');
        }
    }

    private function authorizeRiderOrManager(User $user, Delivery $delivery): void
    {
        if ($user->hasPermission('sales.deliveries.manage', $delivery->store_id)) {
            return;
        }
        $riderContactId = $user->employee?->id;
        if ($user->hasPermission('sales.deliveries.rider') && $riderContactId !== null && $riderContactId === $delivery->rider_id) {
            return;
        }
        throw new BusinessRuleException('This delivery is not assigned to you.', 'not_permitted');
    }
}
