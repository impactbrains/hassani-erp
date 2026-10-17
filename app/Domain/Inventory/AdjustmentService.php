<?php

namespace App\Domain\Inventory;

use App\Domain\Accounting\JournalPoster;
use App\Exceptions\BusinessRuleException;
use App\Models\Location;
use App\Models\ProductVariant;
use App\Models\ReasonCode;
use App\Models\StockAdjustment;
use App\Models\StockAdjustmentLine;
use App\Models\StockBatch;
use App\Models\Store;
use App\Models\User;
use App\Support\Audit;
use App\Support\Decimal;
use App\Support\Sequences;
use App\Support\Settings\Settings;
use Illuminate\Support\Facades\DB;

/**
 * Stock adjustments, write-offs, opening stock and quarantine moves.
 *
 * Every adjustment has a reason. Its value decides the approval path:
 * below the store threshold an approver can post it directly; above it,
 * someone other than the requester must approve; above the dual-approval
 * value, two different approvers are needed. Nothing is posted until then.
 */
final class AdjustmentService
{
    public const KINDS = ['adjustment', 'write_off', 'opening', 'quarantine', 'release'];

    public function __construct(
        private readonly StockLedger $ledger,
        private readonly CostingService $costing,
        private readonly JournalPoster $journals,
    ) {
    }

    /**
     * @param  list<array{variant_id: int, location_id: int, quantity: string, batch_id?: int|null, batch_no?: string|null, expiry_date?: string|null, unit_cost?: string|null, reason_code_id?: int|null, note?: string|null}>  $lines
     */
    public function create(User $user, Store $store, string $kind, array $lines, ?int $reasonCodeId, ?string $note): StockAdjustment
    {
        if (! in_array($kind, self::KINDS, true)) {
            throw new BusinessRuleException('Unknown adjustment type.', 'invalid_kind');
        }
        $permission = in_array($kind, ['quarantine', 'release'], true) ? 'inventory.batches.manage' : 'inventory.adjust';
        if (! $user->hasPermission($permission, $store->id)) {
            throw new BusinessRuleException('You are not allowed to adjust stock.', 'not_permitted');
        }
        $reason = $reasonCodeId ? ReasonCode::query()->find($reasonCodeId) : null;
        if (in_array($kind, ['adjustment', 'write_off'], true) && $reason === null) {
            throw new BusinessRuleException('Choose a reason for the adjustment.', 'reason_required', field: 'reason_code_id');
        }
        if ($reason?->requires_note && blank($note)) {
            throw new BusinessRuleException('This reason needs a note explaining what happened.', 'note_required', field: 'note');
        }
        if ($lines === []) {
            throw new BusinessRuleException('Add at least one product.', 'no_lines');
        }

        return DB::transaction(function () use ($user, $store, $kind, $lines, $reason, $note) {
            $adjustment = StockAdjustment::create([
                'store_id' => $store->id,
                'number' => Sequences::next('stock_adjustment', $store->id, $store->code),
                'kind' => $kind,
                'status' => 'draft',
                'reason_code_id' => $reason?->id,
                'note' => $note,
                'requested_by' => $user->id,
            ]);
            $value = '0';
            foreach ($lines as $line) {
                $quantity = Decimal::qty($line['quantity']);
                if (Decimal::isZero($quantity)) {
                    continue;
                }
                $location = Location::query()->where('store_id', $store->id)->findOrFail($line['location_id']);
                $variant = ProductVariant::query()->with('product')->findOrFail($line['variant_id']);
                if (! $variant->product->isStocked() || $variant->isBundle()) {
                    throw new BusinessRuleException("{$variant->displayName()} does not hold stock of its own.", 'not_stocked');
                }
                $quantity = match ($kind) {
                    'write_off', 'quarantine', 'release' => Decimal::neg(Decimal::abs($quantity)),
                    'opening' => Decimal::abs($quantity),
                    default => $quantity,
                };
                if ($kind === 'opening' && ! isset($line['unit_cost'])) {
                    throw new BusinessRuleException("Enter the cost of {$variant->displayName()} for opening stock (0 if truly unknown).", 'cost_required');
                }
                $unitCost = $line['unit_cost'] ?? $this->costing->currentUnitCost($store->id, $variant->id) ?? '0';
                StockAdjustmentLine::create([
                    'stock_adjustment_id' => $adjustment->id,
                    'variant_id' => $variant->id,
                    'location_id' => $location->id,
                    'to_location_id' => $this->destination($store->id, $kind, $line),
                    'batch_id' => $line['batch_id'] ?? null,
                    'batch_no' => $line['batch_no'] ?? null,
                    'expiry_date' => $line['expiry_date'] ?? null,
                    'quantity' => $quantity,
                    'unit_cost' => Decimal::cost($unitCost),
                    'reason_code_id' => $line['reason_code_id'] ?? $reason?->id,
                    'note' => $line['note'] ?? null,
                ]);
                $value = Decimal::add($value, Decimal::abs(Decimal::mul($quantity, $unitCost)));
            }
            $adjustment->total_value = Decimal::money($value);
            $adjustment->save();
            Audit::record('inventory.adjustment_requested', "Requested {$kind} {$adjustment->number} worth Rs {$adjustment->total_value}", $adjustment);

            return $this->submit($user, $adjustment);
        });
    }

    /** Posts immediately when the requester may approve it; otherwise waits for approval. */
    private function submit(User $user, StockAdjustment $adjustment): StockAdjustment
    {
        $canApprove = $user->hasPermission('inventory.adjust.approve', $adjustment->store_id);
        $threshold = (string) Settings::get('inventory.adjustment_approval_value', $adjustment->store_id);
        $postNow = match (true) {
            in_array($adjustment->kind, ['quarantine', 'release'], true) => true,
            $adjustment->kind === 'opening' => $canApprove,
            $this->needsDualApproval($adjustment) => false,
            $canApprove => true,
            default => Decimal::isPositive($threshold) && Decimal::lte($adjustment->total_value, $threshold),
        };

        if ($postNow) {
            $adjustment->approved_by = $user->id;
            $adjustment->status = 'approved';
            $adjustment->save();

            return $this->post($user, $adjustment);
        }

        $adjustment->status = 'pending_approval';
        $adjustment->save();

        return $adjustment;
    }

    public function approve(User $user, StockAdjustment $adjustment): StockAdjustment
    {
        return DB::transaction(function () use ($user, $adjustment) {
            $adjustment = StockAdjustment::query()->lockForUpdate()->findOrFail($adjustment->id);
            if ($adjustment->status !== 'pending_approval') {
                throw new BusinessRuleException('This adjustment is not waiting for approval.', 'not_pending');
            }
            if (! $user->hasPermission('inventory.adjust.approve', $adjustment->store_id)) {
                throw new BusinessRuleException('You are not allowed to approve adjustments.', 'not_permitted');
            }
            if ($user->id === $adjustment->requested_by && ! $user->isOwner()) {
                throw new BusinessRuleException('Someone other than the requester must approve this adjustment.', 'self_approval');
            }
            if ($adjustment->approved_by === null) {
                $adjustment->approved_by = $user->id;
                Audit::record('inventory.adjustment_approved', "Approved {$adjustment->number}", $adjustment);
                if ($this->needsDualApproval($adjustment)) {
                    $adjustment->save();

                    return $adjustment;
                }
            } else {
                if ($user->id === $adjustment->approved_by) {
                    throw new BusinessRuleException('A second, different approver is needed for this value.', 'second_approver_required');
                }
                $adjustment->second_approved_by = $user->id;
                Audit::record('inventory.adjustment_second_approval', "Second approval of {$adjustment->number}", $adjustment);
            }
            $adjustment->status = 'approved';
            $adjustment->save();

            return $this->post($user, $adjustment);
        });
    }

    public function reject(User $user, StockAdjustment $adjustment, string $reason): StockAdjustment
    {
        if ($adjustment->status !== 'pending_approval' || ! $user->hasPermission('inventory.adjust.approve', $adjustment->store_id)) {
            throw new BusinessRuleException('You cannot reject this adjustment.', 'not_permitted');
        }
        $adjustment->update(['status' => 'rejected', 'note' => trim(($adjustment->note ?? '')."\nRejected: ".$reason)]);
        Audit::record('inventory.adjustment_rejected', "Rejected {$adjustment->number}: {$reason}", $adjustment);

        return $adjustment;
    }

    private function post(User $user, StockAdjustment $adjustment): StockAdjustment
    {
        $adjustment->loadMissing('lines.variant.product', 'store');
        $date = $adjustment->store->businessDate();
        $context = new StockContext($adjustment->getMorphClass(), $adjustment->id, $user->id, $date, approvedBy: $adjustment->approved_by);
        $changes = [];
        foreach ($adjustment->lines as $line) {
            $batchId = $line->batch_id ?? $this->batchFor($line);
            if (in_array($adjustment->kind, ['quarantine', 'release'], true)) {
                $changes[] = new StockChange($adjustment->store_id, $line->location_id, $line->variant_id, $line->quantity, MovementTypes::RELOCATION, $batchId, reasonCodeId: $line->reason_code_id, sourceLineId: $line->id);
                $changes[] = new StockChange($adjustment->store_id, $line->to_location_id, $line->variant_id, Decimal::abs($line->quantity), MovementTypes::RELOCATION, $batchId, reasonCodeId: $line->reason_code_id, sourceLineId: $line->id);

                continue;
            }
            $type = match ($adjustment->kind) {
                'write_off' => MovementTypes::WRITE_OFF,
                'opening' => MovementTypes::OPENING,
                default => MovementTypes::ADJUSTMENT,
            };
            $changes[] = new StockChange($adjustment->store_id, $line->location_id, $line->variant_id, $line->quantity, $type, $batchId, Decimal::isPositive($line->quantity) ? $line->unit_cost : null, $line->reason_code_id, $line->id, $line->note);
        }
        $movements = $this->ledger->post($changes, $context);
        $value = Decimal::sum(array_map(fn ($m) => $m->total_cost ?? '0', $movements));

        $contra = match ($adjustment->kind) {
            'write_off' => 'stock_write_offs',
            'opening' => 'opening_balance_equity',
            default => 'stock_adjustments',
        };
        if (! in_array($adjustment->kind, ['quarantine', 'release'], true)) {
            $this->journals->post('stock_adjustment', $adjustment, $date, ucfirst(str_replace('_', ' ', $adjustment->kind))." {$adjustment->number}", [
                ['account' => 'inventory', 'debit' => $value],
                ['account' => $contra, 'credit' => $value],
            ], $adjustment->store_id);
        }

        $adjustment->fill(['status' => 'posted', 'posted_by' => $user->id, 'posted_at' => now(), 'business_date' => $date, 'total_value' => Decimal::money(Decimal::abs($value) ?: $adjustment->total_value)])->save();
        Audit::record('inventory.adjustment_posted', "Posted {$adjustment->kind} {$adjustment->number}", $adjustment, new: ['value' => $value]);

        return $adjustment->fresh('lines');
    }

    private function needsDualApproval(StockAdjustment $adjustment): bool
    {
        return in_array($adjustment->kind, ['adjustment', 'write_off'], true)
            && Decimal::gt($adjustment->total_value, (string) Settings::get('inventory.dual_approval_value'));
    }

    /** @param array<string, mixed> $line */
    private function destination(int $storeId, string $kind, array $line): ?int
    {
        if ($kind === 'quarantine') {
            return $line['to_location_id'] ?? Location::query()->where('store_id', $storeId)->where('type', 'quarantine')->value('id');
        }
        if ($kind === 'release') {
            return $line['to_location_id'] ?? Location::query()->where('store_id', $storeId)->where('is_default_selling', true)->value('id');
        }

        return null;
    }

    private function batchFor(\App\Models\StockAdjustmentLine $line): ?int
    {
        if (! $line->batch_no || ! Decimal::isPositive($line->quantity)) {
            return null;
        }

        return StockBatch::query()->firstOrCreate(
            ['variant_id' => $line->variant_id, 'batch_no' => $line->batch_no],
            ['expiry_date' => $line->expiry_date, 'status' => 'available'],
        )->id;
    }
}
