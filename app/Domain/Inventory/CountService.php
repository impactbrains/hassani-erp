<?php

namespace App\Domain\Inventory;

use App\Domain\Accounting\JournalPoster;
use App\Exceptions\BusinessRuleException;
use App\Models\StockCount;
use App\Models\StockCountLine;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\Store;
use App\Models\User;
use App\Support\Audit;
use App\Support\Decimal;
use App\Support\Sequences;
use App\Support\Settings\Settings;
use Illuminate\Support\Facades\DB;

/**
 * Physical stock counts and cycle counts:
 *   draft -> in_progress -> submitted -> (second count) -> approved -> posted
 *
 * Expected quantities are snapshotted when counting starts. Sales and other
 * movements that happen while people count are added back at approval, so
 * a busy shop floor does not create false variances:
 *   variance = counted - (snapshot + movements since snapshot)
 * Live stock is never overwritten; only the variance is posted, with value,
 * reason and the names of who counted, verified and approved.
 */
final class CountService
{
    public function __construct(
        private readonly StockLedger $ledger,
        private readonly CostingService $costing,
        private readonly JournalPoster $journals,
    ) {
    }

    /** @param array{location_ids?: list<int>, category_ids?: list<int>, variant_ids?: list<int>} $filter */
    public function plan(User $user, Store $store, string $scope, array $filter, bool $blind, bool $secondCount, ?int $assignedTo, ?string $note): StockCount
    {
        $this->authorize($user, 'inventory.counts.manage', $store->id);

        return StockCount::create([
            'store_id' => $store->id,
            'number' => Sequences::next('stock_count', $store->id, $store->code),
            'scope' => $scope,
            'scope_filter' => $filter,
            'is_blind' => $blind,
            'requires_second_count' => $secondCount,
            'status' => 'draft',
            'assigned_to' => $assignedTo,
            'created_by' => $user->id,
            'note' => $note,
        ]);
    }

    /** Snapshots expected quantities and opens the count for counting. */
    public function start(User $user, StockCount $count): StockCount
    {
        $this->authorize($user, 'inventory.counts.manage', $count->store_id);
        $this->expect($count, ['draft']);

        return DB::transaction(function () use ($count) {
            $filter = $count->scope_filter ?? [];
            // Wait for in-flight sales on these slots to commit, then remember the
            // last ledger row included in the snapshot (see the class comment).
            StockLevel::query()->where('store_id', $count->store_id)->orderBy('id')->lock('FOR SHARE')->get(['id']);
            $watermark = (int) StockMovement::query()->max('id');
            $levels = StockLevel::query()
                ->join('locations', 'locations.id', '=', 'stock_levels.location_id')
                ->join('product_variants', 'product_variants.id', '=', 'stock_levels.variant_id')
                ->join('products', 'products.id', '=', 'product_variants.product_id')
                ->where('stock_levels.store_id', $count->store_id)
                ->where('locations.type', '!=', 'transit')
                ->when(! empty($filter['location_ids']), fn ($q) => $q->whereIn('stock_levels.location_id', $filter['location_ids']))
                ->when(! empty($filter['category_ids']), fn ($q) => $q->whereIn('products.category_id', $filter['category_ids']))
                ->when(! empty($filter['variant_ids']), fn ($q) => $q->whereIn('stock_levels.variant_id', $filter['variant_ids']))
                ->where(fn ($q) => $q->where('stock_levels.on_hand', '>', 0)->orWhereIn('stock_levels.variant_id', $filter['variant_ids'] ?? [0]))
                ->orderBy('locations.code')->orderBy('products.name')
                ->get(['stock_levels.location_id', 'stock_levels.variant_id', 'stock_levels.batch_id', 'stock_levels.on_hand']);

            foreach ($levels as $level) {
                StockCountLine::create([
                    'stock_count_id' => $count->id,
                    'location_id' => $level->location_id,
                    'variant_id' => $level->variant_id,
                    'batch_id' => $level->batch_id,
                    'expected_quantity' => $level->on_hand,
                ]);
            }
            $count->update(['status' => 'in_progress', 'snapshot_at' => now(), 'movement_watermark' => $watermark]);

            return $count;
        });
    }

    /** Adds a line for stock found in a location the snapshot did not list. */
    public function addLine(User $user, StockCount $count, int $locationId, int $variantId, ?int $batchId = null): StockCountLine
    {
        $this->authorize($user, 'inventory.counts.count', $count->store_id);
        $this->expect($count, ['in_progress']);

        return StockCountLine::query()->firstOrCreate(
            ['stock_count_id' => $count->id, 'location_id' => $locationId, 'variant_id' => $variantId, 'batch_id' => $batchId],
            ['expected_quantity' => StockLevel::query()->where('location_id', $locationId)->where('variant_id', $variantId)
                ->when($batchId, fn ($q) => $q->where('batch_id', $batchId), fn ($q) => $q->whereNull('batch_id'))->value('on_hand') ?? '0'],
        );
    }

    public function record(User $user, StockCountLine $line, string $quantity, bool $isSecondCount = false): StockCountLine
    {
        $count = $line->count;
        $this->authorize($user, 'inventory.counts.count', $count->store_id);
        if (Decimal::isNegative($quantity)) {
            throw new BusinessRuleException('A count cannot be negative.', 'invalid_count');
        }
        if ($isSecondCount) {
            $this->expect($count, ['submitted']);
            if ($line->counted_by === $user->id) {
                throw new BusinessRuleException('The second count must be done by someone else.', 'same_counter');
            }
            $line->update(['second_count_quantity' => Decimal::qty($quantity), 'second_counted_by' => $user->id]);
        } else {
            $this->expect($count, ['in_progress']);
            $line->update(['counted_quantity' => Decimal::qty($quantity), 'counted_by' => $user->id, 'counted_at' => now()]);
        }

        return $line;
    }

    public function submit(User $user, StockCount $count): StockCount
    {
        $this->authorize($user, 'inventory.counts.count', $count->store_id);
        $this->expect($count, ['in_progress']);
        $uncounted = $count->lines()->whereNull('counted_quantity')->count();
        if ($uncounted > 0) {
            throw new BusinessRuleException("{$uncounted} lines are not counted yet. Enter 0 for items that are not there.", 'lines_uncounted');
        }
        $count->update(['status' => 'submitted', 'submitted_by' => $user->id]);

        return $count;
    }

    /** Lines that must be recounted by a second person before approval. */
    public function linesNeedingSecondCount(StockCount $count): \Illuminate\Support\Collection
    {
        $threshold = (string) Settings::get('inventory.count_variance_review_percent');

        return $count->lines()->get()->filter(function (StockCountLine $line) use ($count, $threshold) {
            if ($line->second_count_quantity !== null) {
                return false;
            }
            if ($count->requires_second_count) {
                return true;
            }
            $since = StockMovement::query()
                ->where('location_id', $line->location_id)->where('variant_id', $line->variant_id)
                ->when($line->batch_id, fn ($q) => $q->where('batch_id', $line->batch_id), fn ($q) => $q->whereNull('batch_id'))
                ->where('id', '>', (int) $count->movement_watermark)->sum('quantity');
            $expected = Decimal::add($line->expected_quantity, (string) $since);
            $diff = Decimal::abs(Decimal::sub($line->counted_quantity ?? '0', $expected));
            if (Decimal::isZero($diff)) {
                return false;
            }

            return Decimal::isZero($expected) || Decimal::gt(Decimal::div(Decimal::mul($diff, '100'), $expected, 4), $threshold);
        })->values();
    }

    public function approveAndPost(User $user, StockCount $count, array $reasonByLine = []): StockCount
    {
        $this->authorize($user, 'inventory.counts.approve', $count->store_id);

        return DB::transaction(function () use ($user, $count, $reasonByLine) {
            $count = StockCount::query()->lockForUpdate()->findOrFail($count->id);
            $this->expect($count, ['submitted']);
            $pending = $this->linesNeedingSecondCount($count);
            if ($pending->isNotEmpty()) {
                throw new BusinessRuleException("{$pending->count()} lines need a second count by another person before approval.", 'second_count_required');
            }
            $countedBy = $count->lines()->pluck('counted_by')->unique()->filter()->all();
            if (in_array($user->id, $countedBy, true) && count($countedBy) === 1 && ! $user->isOwner()) {
                throw new BusinessRuleException('Someone other than the counter must approve the count.', 'self_approval');
            }

            $date = $count->store->businessDate();
            $context = new StockContext($count->getMorphClass(), $count->id, $user->id, $date, approvedBy: $user->id);
            $changes = [];
            $varianceLines = [];
            foreach ($count->lines()->get() as $line) {
                $final = $line->second_count_quantity ?? $line->counted_quantity;
                $since = StockMovement::query()
                    ->where('location_id', $line->location_id)->where('variant_id', $line->variant_id)
                    ->when($line->batch_id, fn ($q) => $q->where('batch_id', $line->batch_id), fn ($q) => $q->whereNull('batch_id'))
                    ->where('id', '>', (int) $count->movement_watermark)
                    ->sum('quantity');
                $variance = Decimal::qty(Decimal::sub($final, Decimal::add($line->expected_quantity, (string) $since)));
                $unitCost = $this->costing->currentUnitCost($count->store_id, $line->variant_id) ?? '0';
                $line->update([
                    'movement_since_snapshot' => Decimal::qty((string) $since),
                    'variance_quantity' => $variance,
                    'unit_cost' => Decimal::cost($unitCost),
                    'variance_value' => Decimal::money(Decimal::mul($variance, $unitCost)),
                    'reason_code_id' => $reasonByLine[$line->id] ?? $line->reason_code_id,
                ]);
                if (! Decimal::isZero($variance)) {
                    $changes[] = new StockChange($count->store_id, $line->location_id, $line->variant_id, $variance, MovementTypes::COUNT_VARIANCE, $line->batch_id, Decimal::isPositive($variance) ? $unitCost : null, $line->reason_code_id, $line->id, "Count {$count->number}");
                    $varianceLines[] = $line;
                }
            }
            $movements = $this->ledger->post($changes, $context);
            $value = Decimal::sum(array_map(fn ($m) => $m->total_cost ?? '0', $movements));
            $this->journals->post('stock_count', $count, $date, "Count variance {$count->number}", [
                ['account' => 'inventory', 'debit' => $value],
                ['account' => 'stock_count_variance', 'credit' => $value],
            ], $count->store_id);

            $count->update(['status' => 'posted', 'approved_by' => $user->id, 'posted_at' => now()]);
            Audit::record('inventory.count_posted', "Posted count {$count->number}: ".count($varianceLines)." lines with variance, value Rs ".Decimal::money($value), $count);

            return $count->fresh('lines');
        });
    }

    private function expect(StockCount $count, array $statuses): void
    {
        if (! in_array($count->status, $statuses, true)) {
            throw new BusinessRuleException("Count {$count->number} is {$count->status}; this step is not possible now.", 'invalid_status');
        }
    }

    private function authorize(User $user, string $permission, int $storeId): void
    {
        if (! $user->hasPermission($permission, $storeId)) {
            throw new BusinessRuleException('You are not allowed to do this.', 'not_permitted');
        }
    }
}
