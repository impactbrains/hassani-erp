<?php

namespace App\Domain\Inventory;

use App\Models\CostLayer;
use App\Models\CostLayerConsumption;
use App\Models\VariantCost;
use App\Support\Decimal;
use App\Support\Settings\Settings;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Keeps both valuation models up to date for every store and SKU:
 *  - FIFO cost layers (oldest stock is consumed first);
 *  - a moving weighted-average cost.
 *
 * The company setting inventory.valuation_method decides which of the two
 * is used for the cost of goods sold that is posted to the accounts. Both
 * are always maintained so valuation reports can show either.
 */
final class CostingService
{
    public function method(): string
    {
        return (string) Settings::get('inventory.valuation_method');
    }

    /** Records stock entering a store at a known unit cost (per base unit). */
    public function receive(int $storeId, int $variantId, string $quantity, string $unitCost, int $movementId, \DateTimeInterface $at, bool $isPurchase = false): void
    {
        $cost = $this->lockedCost($storeId, $variantId);
        $oldValue = Decimal::mul($cost->quantity, $cost->average_cost);
        $inValue = Decimal::mul($quantity, $unitCost);
        $newQuantity = Decimal::add($cost->quantity, $quantity);

        $cost->average_cost = Decimal::isPositive($newQuantity)
            ? Decimal::div(Decimal::add($oldValue, $inValue), $newQuantity, Decimal::COST_SCALE)
            : Decimal::cost($unitCost);
        $cost->quantity = Decimal::qty($newQuantity);
        if ($isPurchase) {
            $cost->last_purchase_cost = Decimal::cost($unitCost);
        }
        $cost->updated_at = now();
        $cost->save();

        CostLayer::create([
            'store_id' => $storeId,
            'variant_id' => $variantId,
            'movement_id' => $movementId,
            'quantity_in' => Decimal::qty($quantity),
            'quantity_remaining' => Decimal::qty($quantity),
            'unit_cost' => Decimal::cost($unitCost),
            'received_at' => $at,
        ]);
    }

    /**
     * Takes stock out of a store's valuation and returns the cost under the
     * configured method, with the FIFO layers it consumed.
     *
     * @return array{unit_cost: string, total_cost: string, fifo_total: string, average_total: string, consumptions: list<array{layer_id: int, quantity: string, unit_cost: string}>}
     */
    public function consume(int $storeId, int $variantId, string $quantity): array
    {
        $cost = $this->lockedCost($storeId, $variantId);
        $averageTotal = Decimal::money(Decimal::mul($quantity, $cost->average_cost));

        $remaining = $quantity;
        $fifoTotal = '0';
        $consumptions = [];
        $layers = CostLayer::query()
            ->where('store_id', $storeId)
            ->where('variant_id', $variantId)
            ->where('quantity_remaining', '>', 0)
            ->orderBy('received_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        foreach ($layers as $layer) {
            if (! Decimal::isPositive($remaining)) {
                break;
            }
            $take = Decimal::min($remaining, $layer->quantity_remaining);
            $layer->quantity_remaining = Decimal::qty(Decimal::sub($layer->quantity_remaining, $take));
            $layer->save();
            $fifoTotal = Decimal::add($fifoTotal, Decimal::mul($take, $layer->unit_cost));
            $consumptions[] = ['layer_id' => $layer->id, 'quantity' => Decimal::qty($take), 'unit_cost' => $layer->unit_cost];
            $remaining = Decimal::sub($remaining, $take);
        }
        if (Decimal::isPositive($remaining)) {
            // Layers and quantities should always agree; if they do not, value
            // the shortfall at average cost and leave a trace for investigation.
            Log::warning('FIFO layers short of on-hand quantity', ['store' => $storeId, 'variant' => $variantId, 'short' => $remaining]);
            $fifoTotal = Decimal::add($fifoTotal, Decimal::mul($remaining, $cost->average_cost));
        }
        $fifoTotal = Decimal::money($fifoTotal);

        $cost->quantity = Decimal::qty(Decimal::sub($cost->quantity, $quantity));
        if (! Decimal::isPositive($cost->quantity)) {
            $cost->quantity = '0.000';
        }
        $cost->updated_at = now();
        $cost->save();

        $total = $this->method() === 'fifo' ? $fifoTotal : $averageTotal;

        return [
            'unit_cost' => Decimal::isZero($quantity) ? '0' : Decimal::div($total, $quantity, Decimal::COST_SCALE),
            'total_cost' => $total,
            'fifo_total' => $fifoTotal,
            'average_total' => $averageTotal,
            'consumptions' => $consumptions,
        ];
    }

    /** @param list<array{layer_id: int, quantity: string, unit_cost: string}> $consumptions */
    public function recordConsumptions(int $movementId, array $consumptions): void
    {
        foreach ($consumptions as $consumption) {
            CostLayerConsumption::create([
                'cost_layer_id' => $consumption['layer_id'],
                'movement_id' => $movementId,
                'quantity' => $consumption['quantity'],
                'unit_cost' => $consumption['unit_cost'],
            ]);
        }
    }

    /** Current unit cost of a SKU in a store under the configured method (for previews and count valuation). */
    public function currentUnitCost(int $storeId, int $variantId): ?string
    {
        if ($this->method() === 'fifo') {
            $layer = CostLayer::query()
                ->where('store_id', $storeId)->where('variant_id', $variantId)
                ->where('quantity_remaining', '>', 0)
                ->orderBy('received_at')->orderBy('id')
                ->first();
            if ($layer) {
                return $layer->unit_cost;
            }
        }
        $cost = VariantCost::query()->where('store_id', $storeId)->where('variant_id', $variantId)->first();

        return $cost && (Decimal::isPositive($cost->average_cost) || Decimal::isPositive($cost->quantity)) ? $cost->average_cost : ($cost?->last_purchase_cost);
    }

    private function lockedCost(int $storeId, int $variantId): VariantCost
    {
        DB::statement(
            'INSERT INTO variant_costs (company_id, store_id, variant_id, quantity, average_cost, updated_at) VALUES (?, ?, ?, 0, 0, now()) ON CONFLICT (store_id, variant_id) DO NOTHING',
            [CurrentCompany::require(), $storeId, $variantId],
        );

        return VariantCost::query()
            ->where('store_id', $storeId)
            ->where('variant_id', $variantId)
            ->lockForUpdate()
            ->firstOrFail();
    }
}
