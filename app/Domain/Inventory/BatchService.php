<?php

namespace App\Domain\Inventory;

use App\Exceptions\BusinessRuleException;
use App\Models\SalesInvoiceLine;
use App\Models\StockBatch;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Collection;

/**
 * Batch holds and recalls. A quarantined or recalled batch is excluded from
 * sellable stock everywhere at once, without moving it physically; the
 * trace shows where every unit of the batch came from and went.
 */
final class BatchService
{
    public function setStatus(User $user, StockBatch $batch, string $status, string $reason): StockBatch
    {
        if (! in_array($status, ['available', 'quarantined', 'recalled'], true)) {
            throw new BusinessRuleException('Unknown batch status.', 'invalid_status');
        }
        if (! $user->hasPermission('inventory.batches.manage')) {
            throw new BusinessRuleException('You are not allowed to change batch status.', 'not_permitted');
        }
        $old = $batch->status;
        $batch->update(['status' => $status, 'status_reason' => $reason]);
        Audit::record('inventory.batch_status', "Batch {$batch->batch_no} {$old} → {$status}: {$reason}", $batch, ['status' => $old], ['status' => $status]);

        return $batch;
    }

    /** @return array{on_hand: Collection, movements: Collection, sold_to: Collection} */
    public function trace(StockBatch $batch): array
    {
        $movements = StockMovement::query()->where('batch_id', $batch->id)->with('location:id,name', 'store:id,name')->orderBy('occurred_at')->get();
        $saleLineIds = $movements->where('movement_type', 'sale')->pluck('source_line_id')->filter()->unique();

        return [
            'on_hand' => StockLevel::query()->where('batch_id', $batch->id)->where('on_hand', '>', 0)->with('location:id,name', 'store:id,name')->get(),
            'movements' => $movements,
            'sold_to' => SalesInvoiceLine::query()->whereIn('id', $saleLineIds)->with('invoice:id,number,customer_id,customer_name,customer_phone,business_date')->get(),
        ];
    }
}
