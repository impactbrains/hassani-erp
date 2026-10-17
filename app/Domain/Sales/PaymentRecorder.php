<?php

namespace App\Domain\Sales;

use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\PaymentMethod;
use App\Support\Decimal;
use App\Support\Sequences;
use Illuminate\Database\Eloquent\Model;

/**
 * Records a payment and how it is allocated to invoices, bills or credit
 * notes. Wallet, card and bank payments start as "pending" verification:
 * a typed reference number is not proof the money arrived.
 */
final class PaymentRecorder
{
    /**
     * @param  array<string, mixed>  $attributes  store_id, direction, kind, contact_id, shift_id, register_id, received_by, business_date ...
     * @param  list<array{0: Model, 1: string}>  $allocations
     */
    public function record(PaymentMethod $method, string $amount, array $attributes, array $allocations = []): Payment
    {
        $storeCode = \App\Models\Store::query()->whereKey($attributes['store_id'])->value('code');
        $payment = Payment::create($attributes + [
            'number' => Sequences::next('payment', $attributes['store_id'], $storeCode),
            'payment_method_id' => $method->id,
            'amount' => Decimal::money($amount),
            'verification_status' => $method->requires_verification ? 'pending' : 'not_required',
            'received_at' => now(),
        ]);

        foreach ($allocations as [$target, $allocated]) {
            if (! Decimal::isPositive($allocated)) {
                continue;
            }
            PaymentAllocation::create([
                'payment_id' => $payment->id,
                'allocatable_type' => $target->getMorphClass(),
                'allocatable_id' => $target->getKey(),
                'amount' => Decimal::money($allocated),
            ]);
        }

        return $payment;
    }
}
