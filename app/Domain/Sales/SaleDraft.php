<?php

namespace App\Domain\Sales;

use App\Models\Contact;
use App\Models\Register;
use App\Models\SalesOrder;
use App\Models\Shift;
use App\Models\Store;
use App\Models\User;

/** Everything needed to post a sale; built by the POS checkout or the order-to-invoice flow. */
final class SaleDraft
{
    /**
     * @param  list<SaleLine>  $lines
     * @param  list<SalePayment>  $payments
     * @param  array{type: string, value: string}|null  $cartDiscount
     */
    public function __construct(
        public readonly Store $store,
        public readonly string $kind,
        public readonly string $channel,
        public readonly User $user,
        public readonly array $lines,
        public readonly array $payments,
        public readonly string $businessDate,
        public readonly ?Contact $customer = null,
        public readonly ?int $priceBookId = null,
        public readonly ?array $cartDiscount = null,
        public readonly ?int $cartDiscountReasonId = null,
        public readonly ?Register $register = null,
        public readonly ?Shift $shift = null,
        public readonly ?SalesOrder $salesOrder = null,
        public readonly ?int $salesAgentId = null,
        public readonly string $deliveryCharge = '0',
        public readonly ?string $dueDate = null,
        public readonly ?string $idempotencyKey = null,
        public readonly ?string $notes = null,
        public readonly string|int $roundingStep = 0,
        public readonly string $paymentCustody = 'drawer',
        public readonly ?int $riderId = null,
    ) {
    }
}
