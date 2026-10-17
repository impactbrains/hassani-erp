<?php

namespace App\Domain\Inventory;

use App\Domain\Accounting\JournalPoster;
use App\Exceptions\BusinessRuleException;
use App\Models\Location;
use App\Models\ReasonCode;
use App\Models\StockTransfer;
use App\Models\StockTransferLine;
use App\Models\User;
use App\Support\Audit;
use App\Support\Decimal;
use App\Support\Sequences;
use App\Support\Settings\Settings;
use Illuminate\Support\Facades\DB;

/**
 * Moves stock between locations and stores:
 *   draft -> requested -> approved -> dispatched -> received / partially_received
 *
 * On dispatch the goods leave the source location and sit in the receiving
 * store's "in transit" location (still owned, visible as in transit). On
 * receipt they move to the destination location; any shortfall is written
 * off from transit with a discrepancy reason, so nothing silently vanishes.
 */
final class TransferService
{
    public function __construct(private readonly StockLedger $ledger, private readonly JournalPoster $journals)
    {
    }

    /** @param list<array{variant_id: int, quantity: string, batch_id?: int|null}> $lines */
    public function create(User $user, int $fromLocationId, int $toLocationId, array $lines, ?string $note): StockTransfer
    {
        $from = Location::query()->findOrFail($fromLocationId);
        $to = Location::query()->findOrFail($toLocationId);
        if ($from->id === $to->id) {
            throw new BusinessRuleException('Choose two different locations.', 'same_location');
        }
        $this->authorize($user, 'inventory.transfers.manage', $from->store_id);
        if ($lines === []) {
            throw new BusinessRuleException('Add at least one product.', 'no_lines');
        }

        return DB::transaction(function () use ($user, $from, $to, $lines, $note) {
            $transfer = StockTransfer::create([
                'number' => Sequences::next('stock_transfer'),
                'from_store_id' => $from->store_id,
                'from_location_id' => $from->id,
                'to_store_id' => $to->store_id,
                'to_location_id' => $to->id,
                'status' => Settings::get('inventory.transfer_requires_approval') ? 'requested' : 'approved',
                'note' => $note,
                'requested_by' => $user->id,
            ]);
            foreach ($lines as $line) {
                if (! Decimal::isPositive($line['quantity'])) {
                    continue;
                }
                StockTransferLine::create([
                    'stock_transfer_id' => $transfer->id,
                    'variant_id' => $line['variant_id'],
                    'batch_id' => $line['batch_id'] ?? null,
                    'quantity_requested' => Decimal::qty($line['quantity']),
                ]);
            }
            Audit::record('inventory.transfer_requested', "Transfer {$transfer->number} requested", $transfer);

            return $transfer;
        });
    }

    public function approve(User $user, StockTransfer $transfer): StockTransfer
    {
        $this->authorize($user, 'inventory.transfers.approve', $transfer->from_store_id);
        $this->expectStatus($transfer, ['requested']);
        $transfer->update(['status' => 'approved', 'approved_by' => $user->id, 'approved_at' => now()]);
        Audit::record('inventory.transfer_approved', "Transfer {$transfer->number} approved", $transfer);

        return $transfer;
    }

    /** @param array<int, string> $dispatched line id => quantity (defaults to requested) */
    public function dispatch(User $user, StockTransfer $transfer, array $dispatched = []): StockTransfer
    {
        $this->authorize($user, 'inventory.transfers.manage', $transfer->from_store_id);

        return DB::transaction(function () use ($user, $transfer, $dispatched) {
            $transfer = StockTransfer::query()->lockForUpdate()->findOrFail($transfer->id);
            $this->expectStatus($transfer, ['approved']);
            $transit = $this->transitLocation($transfer->to_store_id);
            $date = $transfer->fromStore->businessDate();
            $context = new StockContext($transfer->getMorphClass(), $transfer->id, $user->id, $date);
            $interStore = $transfer->from_store_id !== $transfer->to_store_id;

            $value = '0';
            foreach ($transfer->lines as $line) {
                $quantity = Decimal::qty($dispatched[$line->id] ?? $line->quantity_requested);
                if (! Decimal::isPositive($quantity)) {
                    continue;
                }
                if ($interStore) {
                    [$out] = $this->ledger->post([new StockChange($transfer->from_store_id, $transfer->from_location_id, $line->variant_id, Decimal::neg($quantity), MovementTypes::TRANSFER_OUT, $line->batch_id, sourceLineId: $line->id)], $context);
                    $unitCost = Decimal::div(Decimal::abs($out->total_cost ?? '0'), $quantity, 6);
                    $this->ledger->post([new StockChange($transfer->to_store_id, $transit->id, $line->variant_id, $quantity, MovementTypes::TRANSFER_IN, $line->batch_id, $unitCost, sourceLineId: $line->id)], $context);
                    $value = Decimal::add($value, Decimal::abs($out->total_cost ?? '0'));
                } else {
                    $this->ledger->post([
                        new StockChange($transfer->from_store_id, $transfer->from_location_id, $line->variant_id, Decimal::neg($quantity), MovementTypes::RELOCATION, $line->batch_id, sourceLineId: $line->id),
                        new StockChange($transfer->to_store_id, $transit->id, $line->variant_id, $quantity, MovementTypes::RELOCATION, $line->batch_id, sourceLineId: $line->id),
                    ], $context);
                }
                $line->update(['quantity_dispatched' => $quantity]);
            }
            if ($interStore) {
                $this->journals->post('transfer', $transfer, $date, "Transfer {$transfer->number} between stores", [
                    ['account' => 'inventory', 'debit' => $value, 'store_id' => $transfer->to_store_id],
                    ['account' => 'inventory', 'credit' => $value, 'store_id' => $transfer->from_store_id],
                ]);
            }
            $transfer->update(['status' => 'dispatched', 'dispatched_by' => $user->id, 'dispatched_at' => now()]);
            Audit::record('inventory.transfer_dispatched', "Transfer {$transfer->number} dispatched", $transfer);

            return $transfer->fresh('lines');
        });
    }

    /** @param array<int, array{quantity: string, reason_id?: int|null, note?: string|null}> $received line id => details */
    public function receive(User $user, StockTransfer $transfer, array $received): StockTransfer
    {
        $this->authorize($user, 'inventory.transfers.manage', $transfer->to_store_id);

        return DB::transaction(function () use ($user, $transfer, $received) {
            $transfer = StockTransfer::query()->lockForUpdate()->findOrFail($transfer->id);
            $this->expectStatus($transfer, ['dispatched']);
            $transit = $this->transitLocation($transfer->to_store_id);
            $date = $transfer->toStore->businessDate();
            $context = new StockContext($transfer->getMorphClass(), $transfer->id, $user->id, $date);
            $shortValue = '0';
            $short = false;

            foreach ($transfer->lines as $line) {
                $details = $received[$line->id] ?? ['quantity' => $line->quantity_dispatched];
                $quantity = Decimal::qty($details['quantity']);
                if (Decimal::gt($quantity, $line->quantity_dispatched)) {
                    throw new BusinessRuleException('You cannot receive more than was dispatched. Record extra stock as a separate adjustment.', 'over_receipt');
                }
                if (Decimal::isPositive($quantity)) {
                    $this->ledger->post([
                        new StockChange($transfer->to_store_id, $transit->id, $line->variant_id, Decimal::neg($quantity), MovementTypes::RELOCATION, $line->batch_id, sourceLineId: $line->id),
                        new StockChange($transfer->to_store_id, $transfer->to_location_id, $line->variant_id, $quantity, MovementTypes::RELOCATION, $line->batch_id, sourceLineId: $line->id),
                    ], $context);
                }
                $missing = Decimal::sub($line->quantity_dispatched, $quantity);
                if (Decimal::isPositive($missing)) {
                    $short = true;
                    $reasonId = $details['reason_id'] ?? null;
                    if ($reasonId === null || ! ReasonCode::query()->where('type', 'transfer_discrepancy')->whereKey($reasonId)->exists()) {
                        throw new BusinessRuleException('Choose why some items did not arrive.', 'reason_required', field: 'reason_id');
                    }
                    [$writeOff] = $this->ledger->post([new StockChange($transfer->to_store_id, $transit->id, $line->variant_id, Decimal::neg($missing), MovementTypes::WRITE_OFF, $line->batch_id, reasonCodeId: $reasonId, sourceLineId: $line->id, note: $details['note'] ?? 'Transfer shortfall')], $context);
                    $shortValue = Decimal::add($shortValue, Decimal::abs($writeOff->total_cost ?? '0'));
                    $line->discrepancy_reason_id = $reasonId;
                    $line->discrepancy_note = $details['note'] ?? null;
                }
                $line->quantity_received = $quantity;
                $line->save();
            }
            $this->journals->post('transfer_shortfall', $transfer, $date, "Shortfall on transfer {$transfer->number}", [
                ['account' => 'stock_write_offs', 'debit' => $shortValue],
                ['account' => 'inventory', 'credit' => $shortValue],
            ], $transfer->to_store_id);

            $transfer->update(['status' => $short ? 'partially_received' : 'received', 'received_by' => $user->id, 'received_at' => now()]);
            Audit::record('inventory.transfer_received', "Transfer {$transfer->number} received".($short ? ' with shortfall' : ''), $transfer, new: ['shortfall_value' => $shortValue]);

            return $transfer->fresh('lines');
        });
    }

    public function cancel(User $user, StockTransfer $transfer, string $reason): StockTransfer
    {
        $this->authorize($user, 'inventory.transfers.manage', $transfer->from_store_id);
        $this->expectStatus($transfer, ['draft', 'requested', 'approved']);
        $transfer->update(['status' => $transfer->status === 'requested' ? 'rejected' : 'cancelled', 'note' => trim(($transfer->note ?? '')."\n".$reason)]);
        Audit::record('inventory.transfer_cancelled', "Transfer {$transfer->number} cancelled: {$reason}", $transfer);

        return $transfer;
    }

    private function transitLocation(int $storeId): Location
    {
        return Location::query()->where('store_id', $storeId)->where('type', 'transit')->firstOr(
            fn () => Location::create(['store_id' => $storeId, 'code' => 'TRANSIT', 'name' => 'In transit', 'type' => 'transit'])
        );
    }

    /** @param list<string> $statuses */
    private function expectStatus(StockTransfer $transfer, array $statuses): void
    {
        if (! in_array($transfer->status, $statuses, true)) {
            throw new BusinessRuleException("Transfer {$transfer->number} is {$transfer->status}; this step is not possible now.", 'invalid_status');
        }
    }

    private function authorize(User $user, string $permission, int $storeId): void
    {
        if (! $user->hasPermission($permission, $storeId)) {
            throw new BusinessRuleException('You are not allowed to do this for that store.', 'not_permitted');
        }
    }
}
