<?php

namespace App\Domain\Inventory;

use App\Exceptions\BusinessRuleException;
use App\Models\Location;
use App\Models\ProductVariant;
use App\Models\RepackOrder;
use App\Models\User;
use App\Support\Audit;
use App\Support\Decimal;
use App\Support\Sequences;
use Illuminate\Support\Facades\DB;

/**
 * Repacking bulk goods into retail packs, e.g. a 50 kg sugar bag into
 * 1 kg packs plus packing material. The full cost of what is consumed
 * (including wastage) moves into the packs produced, so inventory value is
 * conserved and the packs carry their true cost.
 */
final class RepackService
{
    public function __construct(private readonly StockLedger $ledger)
    {
    }

    /** @param array{source_variant_id: int, source_location_id: int, source_quantity: string, target_variant_id: int, target_location_id: int, target_quantity: string, packaging_variant_id?: int|null, packaging_quantity?: string|null, note?: string|null} $data */
    public function post(User $user, array $data): RepackOrder
    {
        $sourceLocation = Location::query()->findOrFail($data['source_location_id']);
        $targetLocation = Location::query()->where('store_id', $sourceLocation->store_id)->findOrFail($data['target_location_id']);
        if (! $user->hasPermission('inventory.repack', $sourceLocation->store_id)) {
            throw new BusinessRuleException('You are not allowed to repack stock.', 'not_permitted');
        }
        if ((int) $data['source_variant_id'] === (int) $data['target_variant_id']) {
            throw new BusinessRuleException('Repack into a different product or pack size.', 'same_variant');
        }
        foreach (['source_quantity', 'target_quantity'] as $field) {
            if (! Decimal::isPositive($data[$field] ?? '0')) {
                throw new BusinessRuleException('Quantities must be more than zero.', 'invalid_quantity', field: $field);
            }
        }

        return DB::transaction(function () use ($user, $data, $sourceLocation, $targetLocation) {
            $store = $sourceLocation->store;
            $order = RepackOrder::create([
                'store_id' => $store->id,
                'number' => Sequences::next('repack', $store->id, $store->code),
                'source_variant_id' => $data['source_variant_id'],
                'source_location_id' => $sourceLocation->id,
                'source_quantity' => Decimal::qty($data['source_quantity']),
                'target_variant_id' => $data['target_variant_id'],
                'target_location_id' => $targetLocation->id,
                'target_quantity' => Decimal::qty($data['target_quantity']),
                'packaging_variant_id' => $data['packaging_variant_id'] ?? null,
                'packaging_quantity' => Decimal::qty($data['packaging_quantity'] ?? '0'),
                'status' => 'draft',
                'note' => $data['note'] ?? null,
                'created_by' => $user->id,
            ]);
            $date = $store->businessDate();
            $context = new StockContext($order->getMorphClass(), $order->id, $user->id, $date);

            $consumed = [new StockChange($store->id, $sourceLocation->id, $order->source_variant_id, Decimal::neg($order->source_quantity), MovementTypes::REPACK_CONSUME)];
            if ($order->packaging_variant_id && Decimal::isPositive($order->packaging_quantity)) {
                $consumed[] = new StockChange($store->id, $sourceLocation->id, $order->packaging_variant_id, Decimal::neg($order->packaging_quantity), MovementTypes::REPACK_CONSUME);
            }
            $cost = Decimal::sum(array_map(fn ($m) => Decimal::abs($m->total_cost ?? '0'), $this->ledger->post($consumed, $context)));
            $unitCost = Decimal::div($cost, $order->target_quantity, 6);
            $this->ledger->post([new StockChange($store->id, $targetLocation->id, $order->target_variant_id, $order->target_quantity, MovementTypes::REPACK_PRODUCE, unitCost: $unitCost)], $context);

            $order->update(['status' => 'posted', 'posted_at' => now()]);
            $source = ProductVariant::query()->find($order->source_variant_id);
            Audit::record('inventory.repacked', "Repacked {$order->source_quantity} of {$source?->sku} into {$order->target_quantity} packs ({$order->number})", $order, new: ['cost' => $cost, 'unit_cost' => $unitCost]);

            return $order;
        });
    }
}
