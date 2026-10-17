<?php

namespace App\Domain\Sales;

use App\Models\PaymentMethod;

/** A tender taken against a sale (or collection), before it is recorded. */
final class SalePayment
{
    public function __construct(
        public readonly PaymentMethod $method,
        public readonly string $amount,
        public readonly ?string $tendered = null,
        public readonly string $change = '0.00',
        public readonly ?string $reference = null,
        public readonly ?string $terminalLabel = null,
    ) {
    }
}
