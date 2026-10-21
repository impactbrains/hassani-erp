<?php

namespace App\Domain\Pos;

use App\Domain\Inventory\Availability;
use App\Domain\Sales\SaleLine;
use App\Support\Decimal;

/**
 * Lists every cart item that cannot be sold from the stock on hand ("needs 5, only 2"),
 * so the cashier sees all problems at once instead of one error per attempt.
 * Several lines of the same product (e.g. a piece and a carton) are added together.
 * Negative stock is never allowed: this is the same rule the sale itself enforces.
 */
final class StockShortages
{
    public function __construct(private readonly Availability $availability) {}

    /**
     * @param  list<SaleLine>  $lines
     * @return list<array{variant_id: int, name: string, needs: string, available: string}>
     */
    public function forLines(int $storeId, array $lines, ?string $date = null): array
    {
        $needs = [];
        $names = [];
        foreach ($lines as $line) {
            if (! $line->variant->product->isStocked() || $line->variant->product->is_open_item) {
                continue;
            }
            $id = $line->variant->id;
            $needs[$id] = Decimal::add($needs[$id] ?? '0', $line->baseQuantity);
            $names[$id] = $line->variant->displayName();
        }
        if ($needs === []) {
            return [];
        }
        $available = $this->availability->availableMany($storeId, array_keys($needs), $date);
        $short = [];
        foreach ($needs as $id => $need) {
            $have = $available[$id] ?? '0';
            if (Decimal::lt($have, $need)) {
                $short[] = ['variant_id' => $id, 'name' => $names[$id], 'needs' => Decimal::trim($need), 'available' => Decimal::trim($have)];
            }
        }

        return $short;
    }
}
