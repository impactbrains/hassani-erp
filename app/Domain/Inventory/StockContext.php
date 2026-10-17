<?php

namespace App\Domain\Inventory;

use Illuminate\Support\Str;

/** Who/what/when for a group of stock changes posted together. */
final class StockContext
{
    public readonly string $operationId;

    public function __construct(
        public readonly ?string $sourceType,
        public readonly ?int $sourceId,
        public readonly ?int $userId,
        public readonly string $businessDate,
        public readonly ?\DateTimeInterface $occurredAt = null,
        public readonly ?int $approvedBy = null,
    ) {
        $this->operationId = (string) Str::uuid();
    }
}
