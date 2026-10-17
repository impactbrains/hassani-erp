<?php

namespace App\Domain\Purchasing;

use App\Domain\Inventory\Availability;
use App\Models\ForecastSeries;
use App\Models\ProductVariant;
use App\Models\PurchaseOrderLine;
use App\Models\ReplenishmentPolicy;
use App\Models\StockMovement;
use App\Models\SupplierProduct;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;

/**
 * Reorder suggestions. For every SKU:
 *   daily demand   = latest validated forecast, else last 28 days of sales / 28
 *   reorder point  = policy value, else demand x lead time + safety stock
 *   suggest when   available + on order <= reorder point
 *   order quantity = (max level, or reorder point + 14 days of demand)
 *                    - (available + on order), rounded up to the order multiple
 *                    and the supplier's minimum order quantity.
 * Suggestions are only suggestions: a person reviews them and creates a
 * draft purchase order; nothing is ordered automatically.
 */
final class ReplenishmentService
{
    private const HISTORY_DAYS = 28;

    private const DEFAULT_COVER_DAYS = 14;

    private const DEFAULT_LEAD_DAYS = 3;

    public function __construct(private readonly Availability $availability)
    {
    }

    /** @return list<array<string, mixed>> */
    public function suggestions(int $storeId, ?int $supplierId = null, bool $onlyBelowReorder = true): array
    {
        $policies = ReplenishmentPolicy::query()->where('store_id', $storeId)->where('is_active', true)->get()->keyBy('variant_id');
        $since = now()->subDays(self::HISTORY_DAYS);
        $demand = StockMovement::query()
            ->where('store_id', $storeId)
            ->whereIn('movement_type', ['sale', 'bundle_consume'])
            ->where('occurred_at', '>=', $since)
            ->groupBy('variant_id')
            ->selectRaw('variant_id, -SUM(quantity) as sold')
            ->pluck('sold', 'variant_id');
        $onOrder = PurchaseOrderLine::query()
            ->join('purchase_orders', 'purchase_orders.id', '=', 'purchase_order_lines.purchase_order_id')
            ->where('purchase_orders.store_id', $storeId)
            ->whereIn('purchase_orders.status', ['approved', 'sent', 'partially_received'])
            ->groupBy('purchase_order_lines.variant_id')
            ->selectRaw('purchase_order_lines.variant_id, SUM(purchase_order_lines.base_quantity - purchase_order_lines.base_quantity_received - purchase_order_lines.base_quantity_cancelled) as open_quantity')
            ->pluck('open_quantity', 'variant_id');
        $forecasts = $this->forecastDailyDemand($storeId);

        $variantIds = collect($policies->keys())->merge($demand->keys())->unique()->values()->all();
        $variants = ProductVariant::query()->with('product:id,name,type', 'baseUnit:id,code')->whereIn('id', $variantIds)->where('is_active', true)->get()->keyBy('id');
        $available = $this->availability->availableMany($storeId, $variantIds);
        $supplierLinks = SupplierProduct::query()->with('supplier:id,name', 'variantUnit:id,factor,unit_id', 'variantUnit.unit:id,code')
            ->whereIn('variant_id', $variantIds)->orderByDesc('is_preferred')->get()->groupBy('variant_id');

        $rows = [];
        foreach ($variants as $variant) {
            if (! $variant->product?->isStocked() || $variant->isBundle()) {
                continue;
            }
            $policy = $policies[$variant->id] ?? null;
            $link = $supplierLinks[$variant->id][0] ?? null;
            $preferredSupplier = $policy?->preferred_supplier_id ?? $link?->supplier_id;
            if ($supplierId && $preferredSupplier !== $supplierId) {
                continue;
            }
            $historyDemand = Decimal::div((string) ($demand[$variant->id] ?? '0'), (string) self::HISTORY_DAYS, 4);
            $daily = $forecasts[$variant->id] ?? $historyDemand;
            $demandSource = isset($forecasts[$variant->id]) ? 'validated forecast' : 'last '.self::HISTORY_DAYS.' days of sales';
            $leadDays = $policy?->lead_time_days ?? $link?->lead_time_days ?? self::DEFAULT_LEAD_DAYS;
            $safety = $policy?->safety_stock ?? '0';
            $reorderPoint = $policy?->reorder_point ?? Decimal::qty(Decimal::add(Decimal::mul($daily, (string) $leadDays), $safety));
            $position = Decimal::add($available[$variant->id] ?? '0', (string) ($onOrder[$variant->id] ?? '0'));
            $below = Decimal::lte($position, $reorderPoint) && (Decimal::isPositive($reorderPoint) || Decimal::isPositive($policy?->min_quantity ?? '0'));
            if ($policy && Decimal::lt($position, $policy->min_quantity)) {
                $below = true;
            }
            if ($onlyBelowReorder && ! $below) {
                continue;
            }
            $target = $policy?->max_quantity ?? Decimal::add($reorderPoint, Decimal::mul($daily, (string) self::DEFAULT_COVER_DAYS));
            $quantity = Decimal::max('0', Decimal::sub($target, $position));
            $multiple = $policy?->order_multiple ?? '1';
            if ($link && Decimal::gt($link->variantUnit?->factor ?? '1', $multiple)) {
                $multiple = $link->variantUnit->factor; // order in whole cartons
            }
            $quantity = $this->roundUp($quantity, $multiple);
            if ($link && Decimal::lt(Decimal::div($quantity, $link->variantUnit?->factor ?? '1', 3), $link->min_order_quantity)) {
                $quantity = Decimal::mul($link->min_order_quantity, $link->variantUnit?->factor ?? '1');
            }

            $rows[] = [
                'variant_id' => $variant->id,
                'sku' => $variant->sku,
                'name' => $variant->displayName(),
                'unit' => $variant->baseUnit?->code,
                'available' => $available[$variant->id] ?? '0.000',
                'on_order' => Decimal::qty((string) ($onOrder[$variant->id] ?? '0')),
                'daily_demand' => Decimal::round($daily, 3),
                'demand_source' => $demandSource,
                'lead_time_days' => $leadDays,
                'safety_stock' => Decimal::qty($safety),
                'reorder_point' => Decimal::qty($reorderPoint),
                'suggested_quantity' => Decimal::qty($quantity),
                'days_of_cover' => Decimal::isPositive($daily) ? Decimal::round(Decimal::div($position, $daily, 2), 1) : null,
                'supplier_id' => $preferredSupplier,
                'supplier_name' => $link?->supplier?->name,
                'purchase_unit_id' => $link?->variant_unit_id,
                'purchase_unit' => $link?->variantUnit?->unit?->code,
                'purchase_factor' => $link?->variantUnit?->factor ?? '1',
                'below_reorder_point' => $below,
                'stockout_risk' => Decimal::isPositive($daily) && Decimal::lt(Decimal::div($position, $daily, 2), (string) $leadDays),
            ];
        }
        usort($rows, fn ($a, $b) => [$b['stockout_risk'], (float) $a['days_of_cover']] <=> [$a['stockout_risk'], (float) $b['days_of_cover']]);

        return $rows;
    }

    /** @return array<int, string> variant id => daily demand from the latest validated forecast */
    private function forecastDailyDemand(int $storeId): array
    {
        $series = ForecastSeries::query()
            ->where('store_id', $storeId)->where('level', 'variant')->where('quality', 'validated')
            ->whereIn('forecast_run_id', fn ($q) => $q->select(DB::raw('MAX(id)'))->from('forecast_runs')->where('store_id', $storeId)->where('level', 'variant'))
            ->with(['points' => fn ($q) => $q->orderBy('period_start')->limit(1)])
            ->get();
        $result = [];
        foreach ($series as $item) {
            $first = $item->points->first();
            if ($first) {
                $result[(int) $item->level_id] = Decimal::div($first->predicted, '7', 4);
            }
        }

        return $result;
    }

    private function roundUp(string $quantity, string $multiple): string
    {
        if (! Decimal::isPositive($multiple) || Decimal::isZero($quantity)) {
            return Decimal::qty($quantity);
        }
        $units = Decimal::div($quantity, $multiple, 6);
        $whole = Decimal::round($units, 0);
        if (Decimal::lt($whole, $units)) {
            $whole = Decimal::add($whole, '1');
        }

        return Decimal::qty(Decimal::mul($whole, $multiple));
    }
}
