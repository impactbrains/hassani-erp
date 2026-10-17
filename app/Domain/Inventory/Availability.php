<?php

namespace App\Domain\Inventory;

use App\Exceptions\BusinessRuleException;
use App\Models\Location;
use App\Models\ProductVariant;
use App\Models\StockLevel;
use App\Models\StockReservation;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;

/**
 * Stock availability definitions (see docs/metric-dictionary.md):
 *  - on hand: everything physically in the store's locations;
 *  - sellable: on hand in sellable locations, excluding quarantined,
 *    recalled and expired batches;
 *  - reserved: active reservations for confirmed orders;
 *  - available: sellable minus reserved.
 */
final class Availability
{
    public function available(int $storeId, int $variantId, ?string $asOfDate = null, bool $lock = false): string
    {
        return Decimal::qty(Decimal::sub($this->sellable($storeId, $variantId, $asOfDate, $lock), $this->reserved($storeId, $variantId)));
    }

    public function sellable(int $storeId, int $variantId, ?string $asOfDate = null, bool $lock = false): string
    {
        $query = $this->sellableLevels($storeId, $asOfDate)->where('stock_levels.variant_id', $variantId);
        if ($lock) {
            $rows = $query->orderBy('stock_levels.id')->lock('FOR UPDATE OF stock_levels')->get(['stock_levels.on_hand']);

            return Decimal::qty(Decimal::sum($rows->pluck('on_hand')));
        }

        return Decimal::qty((string) ($query->sum('stock_levels.on_hand') ?? '0'));
    }

    public function reserved(int $storeId, int $variantId, ?int $exceptSourceId = null, ?string $exceptSourceType = null): string
    {
        $sum = StockReservation::query()
            ->where('store_id', $storeId)
            ->where('variant_id', $variantId)
            ->where('status', 'active')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->when($exceptSourceType, fn ($q) => $q->whereNot(fn ($w) => $w->where('source_type', $exceptSourceType)->where('source_id', $exceptSourceId)))
            ->sum('quantity');

        return Decimal::qty((string) $sum);
    }

    /**
     * Locks the SKU's sellable stock in the store and fails if the quantity is
     * not available, taking reservations for other orders into account.
     */
    public function assertAvailable(int $storeId, ProductVariant $variant, string $quantity, ?string $asOfDate = null, ?string $ownReservationType = null, ?int $ownReservationId = null): void
    {
        $sellable = $this->sellable($storeId, $variant->id, $asOfDate, lock: true);
        $reserved = $this->reserved($storeId, $variant->id, $ownReservationId, $ownReservationType);
        $available = Decimal::sub($sellable, $reserved);
        if (Decimal::lt($available, $quantity)) {
            throw new BusinessRuleException(
                'Not enough stock of '.$variant->displayName().': '.Decimal::trim(Decimal::max($available, '0')).' available, '.Decimal::trim($quantity).' needed.',
                'insufficient_stock',
                ['variant_id' => $variant->id, 'available' => Decimal::qty(Decimal::max($available, '0'))],
            );
        }
    }

    /**
     * Per-status breakdown for one SKU in a store.
     *
     * @return array{on_hand: string, sellable: string, quarantined: string, damaged: string, in_transit: string, expired: string, reserved: string, available: string}
     */
    public function breakdown(int $storeId, int $variantId, ?string $asOfDate = null): array
    {
        $date = $asOfDate ?? now()->toDateString();
        $rows = StockLevel::query()
            ->join('locations', 'locations.id', '=', 'stock_levels.location_id')
            ->leftJoin('stock_batches', 'stock_batches.id', '=', 'stock_levels.batch_id')
            ->where('stock_levels.store_id', $storeId)
            ->where('stock_levels.variant_id', $variantId)
            ->where('stock_levels.on_hand', '>', 0)
            ->get(['stock_levels.on_hand', 'locations.type', 'locations.is_sellable', 'stock_batches.status as batch_status', 'stock_batches.expiry_date']);

        $totals = ['on_hand' => '0', 'sellable' => '0', 'quarantined' => '0', 'damaged' => '0', 'in_transit' => '0', 'expired' => '0'];
        foreach ($rows as $row) {
            $totals['on_hand'] = Decimal::add($totals['on_hand'], $row->on_hand);
            $expired = $row->expiry_date !== null && $row->expiry_date < $date;
            $blockedBatch = $row->batch_status !== null && $row->batch_status !== 'available';
            if ($row->type === 'transit') {
                $totals['in_transit'] = Decimal::add($totals['in_transit'], $row->on_hand);
            } elseif ($row->type === 'damaged') {
                $totals['damaged'] = Decimal::add($totals['damaged'], $row->on_hand);
            } elseif ($row->type === 'quarantine' || $blockedBatch) {
                $totals['quarantined'] = Decimal::add($totals['quarantined'], $row->on_hand);
            } elseif ($expired) {
                $totals['expired'] = Decimal::add($totals['expired'], $row->on_hand);
            } elseif ($row->is_sellable) {
                $totals['sellable'] = Decimal::add($totals['sellable'], $row->on_hand);
            }
        }
        $reserved = $this->reserved($storeId, $variantId);
        $totals = array_map(fn ($v) => Decimal::qty($v), $totals);
        $totals['reserved'] = $reserved;
        $totals['available'] = Decimal::qty(Decimal::max('0', Decimal::sub($totals['sellable'], $reserved)));

        return $totals;
    }

    /** @param list<int> $variantIds @return array<int, string> available per variant */
    public function availableMany(int $storeId, array $variantIds, ?string $asOfDate = null): array
    {
        if ($variantIds === []) {
            return [];
        }
        $sellable = $this->sellableLevels($storeId, $asOfDate)
            ->whereIn('stock_levels.variant_id', $variantIds)
            ->groupBy('stock_levels.variant_id')
            ->selectRaw('stock_levels.variant_id, SUM(stock_levels.on_hand) as total')
            ->pluck('total', 'variant_id');
        $reserved = StockReservation::query()
            ->where('store_id', $storeId)->whereIn('variant_id', $variantIds)->where('status', 'active')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->groupBy('variant_id')
            ->selectRaw('variant_id, SUM(quantity) as total')
            ->pluck('total', 'variant_id');

        $result = [];
        foreach ($variantIds as $id) {
            $result[$id] = Decimal::qty(Decimal::max('0', Decimal::sub((string) ($sellable[$id] ?? '0'), (string) ($reserved[$id] ?? '0'))));
        }

        return $result;
    }

    private function sellableLevels(int $storeId, ?string $asOfDate): \Illuminate\Database\Eloquent\Builder
    {
        $date = $asOfDate ?? now()->toDateString();

        return StockLevel::query()
            ->join('locations', 'locations.id', '=', 'stock_levels.location_id')
            ->leftJoin('stock_batches', 'stock_batches.id', '=', 'stock_levels.batch_id')
            ->where('stock_levels.store_id', $storeId)
            ->where('locations.is_sellable', true)
            ->where('locations.is_active', true)
            ->where(fn ($q) => $q->whereNull('stock_levels.batch_id')
                ->orWhere(fn ($b) => $b->where('stock_batches.status', 'available')
                    ->where(fn ($e) => $e->whereNull('stock_batches.expiry_date')->orWhere('stock_batches.expiry_date', '>=', $date))));
    }

    public static function sellableLocationIds(int $storeId): array
    {
        return Location::query()->where('store_id', $storeId)->where('is_sellable', true)->where('is_active', true)->pluck('id')->all();
    }
}
