<?php

namespace App\Domain\Inventory;

use App\Exceptions\BusinessRuleException;
use App\Models\ProductVariant;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Support\Decimal;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;

/**
 * The only way stock changes. Posts a group of changes atomically:
 *
 *  1. locks the affected stock_levels rows in a fixed order (no deadlocks
 *     between two terminals selling the same items);
 *  2. refuses any change that would take a location below zero;
 *  3. writes immutable stock_movements rows;
 *  4. updates FIFO layers and the weighted-average cost.
 *
 * Callers wrap this in their own transaction together with the document
 * (sale, receipt, count ...) and its journal entry, so everything commits
 * or rolls back together.
 */
final class StockLedger
{
    public function __construct(private readonly CostingService $costing)
    {
    }

    /**
     * @param  list<StockChange>  $changes
     * @return list<StockMovement> movements in the order of $changes
     */
    public function post(array $changes, StockContext $context): array
    {
        if ($changes === []) {
            return [];
        }
        if (DB::transactionLevel() === 0) {
            return DB::transaction(fn () => $this->post($changes, $context));
        }

        $this->lockLevels($changes);

        $movements = [];
        foreach ($changes as $index => $change) {
            $movements[$index] = $this->apply($change, $context);
        }

        return array_values($movements);
    }

    /** @param list<StockChange> $changes */
    private function lockLevels(array $changes): void
    {
        $slots = [];
        foreach ($changes as $change) {
            $slots[$change->locationId.'-'.$change->variantId.'-'.($change->batchId ?? 0)] = $change;
        }
        ksort($slots, SORT_NATURAL);

        foreach ($slots as $change) {
            DB::statement(
                'INSERT INTO stock_levels (company_id, store_id, location_id, variant_id, batch_id, on_hand, updated_at)
                 VALUES (?, ?, ?, ?, ?, 0, now())
                 ON CONFLICT (location_id, variant_id, COALESCE(batch_id, 0)) DO NOTHING',
                [CurrentCompany::require(), $change->storeId, $change->locationId, $change->variantId, $change->batchId],
            );
            $this->levelQuery($change)->lockForUpdate()->first();
        }
    }

    private function apply(StockChange $change, StockContext $context): StockMovement
    {
        if (Decimal::isZero($change->quantity)) {
            throw new \InvalidArgumentException('A stock change cannot be zero.');
        }

        $level = $this->levelQuery($change)->firstOrFail();
        $newOnHand = Decimal::add($level->on_hand, $change->quantity);
        if (Decimal::isNegative($newOnHand)) {
            $variant = ProductVariant::query()->with('product')->find($change->variantId);
            $name = $variant?->displayName() ?? "SKU #{$change->variantId}";
            throw new BusinessRuleException(
                "Not enough stock of {$name}: {$this->trim($level->on_hand)} available, ".$this->trim(Decimal::abs($change->quantity)).' needed.',
                'insufficient_stock',
                ['variant_id' => $change->variantId, 'location_id' => $change->locationId, 'available' => $level->on_hand],
            );
        }
        $level->on_hand = Decimal::qty($newOnHand);
        $level->updated_at = now();
        $level->save();

        $isInbound = Decimal::isPositive($change->quantity);
        $quantityAbs = Decimal::abs($change->quantity);
        $affectsValue = MovementTypes::affectsValuation($change->type);

        $unitCost = null;
        $totalCost = null;
        $consumptions = [];
        if ($affectsValue && ! $isInbound) {
            $cost = $this->costing->consume($change->storeId, $change->variantId, $quantityAbs);
            $unitCost = $cost['unit_cost'];
            $totalCost = Decimal::neg($cost['total_cost']);
            $consumptions = $cost['consumptions'];
        } elseif ($affectsValue) {
            $unitCost = Decimal::cost($change->unitCost ?? $this->costing->currentUnitCost($change->storeId, $change->variantId) ?? '0');
            $totalCost = Decimal::money(Decimal::mul($quantityAbs, $unitCost));
        } else {
            $unitCost = $this->costing->currentUnitCost($change->storeId, $change->variantId);
        }

        $movement = StockMovement::create([
            'store_id' => $change->storeId,
            'location_id' => $change->locationId,
            'variant_id' => $change->variantId,
            'batch_id' => $change->batchId,
            'quantity' => Decimal::qty($change->quantity),
            'movement_type' => $change->type,
            'reason_code_id' => $change->reasonCodeId,
            'unit_cost' => $unitCost,
            'total_cost' => $totalCost,
            'source_type' => $context->sourceType,
            'source_id' => $context->sourceId,
            'source_line_id' => $change->sourceLineId,
            'operation_id' => $context->operationId,
            'user_id' => $context->userId,
            'approved_by' => $context->approvedBy,
            'note' => $change->note,
            'occurred_at' => $context->occurredAt ?? now(),
            'business_date' => $context->businessDate,
        ]);

        if ($affectsValue && $isInbound) {
            $this->costing->receive(
                $change->storeId,
                $change->variantId,
                $quantityAbs,
                $unitCost ?? '0',
                $movement->id,
                $context->occurredAt ?? now(),
                $change->type === MovementTypes::RECEIPT,
            );
        } elseif ($consumptions !== []) {
            $this->costing->recordConsumptions($movement->id, $consumptions);
        }

        return $movement;
    }

    private function levelQuery(StockChange $change): \Illuminate\Database\Eloquent\Builder
    {
        return StockLevel::query()
            ->where('location_id', $change->locationId)
            ->where('variant_id', $change->variantId)
            ->when($change->batchId, fn ($q) => $q->where('batch_id', $change->batchId), fn ($q) => $q->whereNull('batch_id'));
    }

    private function trim(string $quantity): string
    {
        return Decimal::trim($quantity);
    }
}
