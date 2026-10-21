<?php

namespace App\Domain\Catalog;

use App\Models\Contact;

/** Who is buying, where and when: what price rules look at besides the cart itself. */
final class PriceContext
{
    public function __construct(
        public readonly int $storeId,
        public readonly ?Contact $customer,
        public readonly string $date, // business date, Y-m-d
    ) {
    }
}
