<?php

namespace App\Domain\Inventory;

use App\Exceptions\BusinessRuleException;
use App\Models\ProductVariant;
use App\Models\StockLevel;
use App\Support\Decimal;

/**
 * Chooses which location and batch a sale takes stock from.
 *
 * Batch-tracked goods follow first-expiry-first-out (FEFO) and never take
 * expired, quarantined or recalled batches. Other goods come from the
 * register's own location first, then any other sellable location.
 */
final class SaleAllocator
{
    /** @return list<array{location_id: int, batch_id: ?int, quantity: string}> */
    public function allocate(int $storeId, ?int $preferredLocationId, ProductVariant $variant, string $quantity, string $businessDate): array
    {
        $levels = StockLevel::query()
            ->join('locations', 'locations.id', '=', 'stock_levels.location_id')
            ->leftJoin('stock_batches', 'stock_batches.id', '=', 'stock_levels.batch_id')
            ->where('stock_levels.store_id', $storeId)
            ->where('stock_levels.variant_id', $variant->id)
            ->where('stock_levels.on_hand', '>', 0)
            ->where('locations.is_sellable', true)
            ->where('locations.is_active', true)
            ->where(fn ($q) => $q->whereNull('stock_levels.batch_id')
                ->orWhere(fn ($b) => $b->where('stock_batches.status', 'available')
                    ->where(fn ($e) => $e->whereNull('stock_batches.expiry_date')->orWhere('stock_batches.expiry_date', '>=', $businessDate))))
            ->orderByRaw('CASE WHEN stock_levels.location_id = ? THEN 0 ELSE 1 END', [$preferredLocationId ?? 0])
            ->orderByRaw('stock_batches.expiry_date ASC NULLS LAST')
            ->orderBy('stock_levels.id')
            ->get(['stock_levels.location_id', 'stock_levels.batch_id', 'stock_levels.on_hand', 'stock_batches.expiry_date']);

        if ($variant->product?->track_expiry || $variant->product?->track_batches) {
            // FEFO beats the preferred location for perishable goods.
            $levels = $levels->sortBy(fn ($row) => [$row->expiry_date ?? '9999-12-31', $row->location_id === $preferredLocationId ? 0 : 1])->values();
        }

        $remaining = $quantity;
        $allocations = [];
        foreach ($levels as $level) {
            if (! Decimal::isPositive($remaining)) {
                break;
            }
            $take = Decimal::min($remaining, $level->on_hand);
            $allocations[] = ['location_id' => (int) $level->location_id, 'batch_id' => $level->batch_id ? (int) $level->batch_id : null, 'quantity' => Decimal::qty($take)];
            $remaining = Decimal::sub($remaining, $take);
        }

        if (Decimal::isPositive($remaining)) {
            $available = Decimal::sub($quantity, $remaining);
            throw new BusinessRuleException(
                'Not enough sellable stock of '.$variant->displayName().': '.Decimal::trim($available).' available, '.Decimal::trim($quantity).' needed.',
                'insufficient_stock',
                ['variant_id' => $variant->id, 'available' => Decimal::qty($available)],
            );
        }

        return $allocations;
    }
}
