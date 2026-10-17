<?php

namespace App\Domain\Purchasing;

use App\Domain\Accounting\JournalPoster;
use App\Domain\Inventory\MovementTypes;
use App\Domain\Inventory\StockChange;
use App\Domain\Inventory\StockContext;
use App\Domain\Inventory\StockLedger;
use App\Exceptions\BusinessRuleException;
use App\Models\Contact;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\LandedCost;
use App\Models\Location;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\StockBatch;
use App\Models\Store;
use App\Models\User;
use App\Models\VariantUnit;
use App\Support\Audit;
use App\Support\Decimal;
use App\Support\Sequences;
use App\Support\Settings\Settings;
use Illuminate\Support\Facades\DB;

/**
 * Goods receiving (GRN). Received goods enter the store at cost including
 * their share of landed costs (freight, loading, duty) allocated by value or
 * quantity. Accepted goods go to the receiving location, goods on QC hold to
 * quarantine (their batch is held too), damaged goods to the damaged area.
 * Accounting: Dr Inventory, Cr Goods-received-not-invoiced; the supplier
 * bill later clears GRNI, so receipts and liabilities never drift apart.
 */
final class ReceivingService
{
    public function __construct(private readonly StockLedger $ledger, private readonly JournalPoster $journals)
    {
    }

    /**
     * @param  array{supplier_id: int, purchase_order_id?: int|null, location_id?: int|null, supplier_reference?: string|null, notes?: string|null, is_blind?: bool,
     *     lines: list<array{variant_id: int, variant_unit_id: int, purchase_order_line_id?: int|null, quantity_received: string, quantity_damaged?: string|null,
     *     unit_price: string, batch_no?: string|null, expiry_date?: string|null, quality_status?: string|null, discrepancy_note?: string|null}>,
     *     landed_costs?: list<array{type: string, amount: string, description?: string|null, allocation_method?: string|null}>}  $data
     */
    public function receive(User $user, Store $store, array $data): GoodsReceipt
    {
        if (! $user->hasPermission('purchasing.receive', $store->id)) {
            throw new BusinessRuleException('You are not allowed to receive goods.', 'not_permitted');
        }
        $supplier = Contact::query()->ofType('supplier')->findOrFail($data['supplier_id']);
        $order = isset($data['purchase_order_id']) ? PurchaseOrder::query()->where('store_id', $store->id)->findOrFail($data['purchase_order_id']) : null;
        if ($order && ! in_array($order->status, ['approved', 'sent', 'partially_received'], true)) {
            throw new BusinessRuleException("Purchase order {$order->number} is {$order->status} and cannot be received against.", 'invalid_status');
        }
        if ($order && $order->supplier_id !== $supplier->id) {
            throw new BusinessRuleException('The purchase order belongs to a different supplier.', 'supplier_mismatch');
        }
        $location = isset($data['location_id'])
            ? Location::query()->where('store_id', $store->id)->findOrFail($data['location_id'])
            : $store->defaultReceivingLocation();

        return DB::transaction(function () use ($user, $store, $data, $supplier, $order, $location) {
            $receipt = GoodsReceipt::create([
                'store_id' => $store->id,
                'number' => Sequences::next('goods_receipt', $store->id, $store->code),
                'supplier_id' => $supplier->id,
                'purchase_order_id' => $order?->id,
                'location_id' => $location->id,
                'status' => 'draft',
                'supplier_reference' => $data['supplier_reference'] ?? null,
                'is_blind' => (bool) ($data['is_blind'] ?? false),
                'notes' => $data['notes'] ?? null,
                'received_by' => $user->id,
                'received_at' => now(),
            ]);

            $lines = [];
            foreach ($data['lines'] as $row) {
                $lines[] = $this->createLine($receipt, $order, $row);
            }
            if ($lines === []) {
                throw new BusinessRuleException('Enter at least one received product.', 'no_lines');
            }
            $landed = $this->allocateLandedCosts($receipt, $lines, $data['landed_costs'] ?? []);

            return $this->post($user, $receipt, $lines, $landed);
        });
    }

    /** @param array<string, mixed> $row */
    private function createLine(GoodsReceipt $receipt, ?PurchaseOrder $order, array $row): GoodsReceiptLine
    {
        $unit = VariantUnit::query()->where('variant_id', $row['variant_id'])->findOrFail($row['variant_unit_id']);
        $variant = ProductVariant::query()->with('product')->findOrFail($row['variant_id']);
        $received = Decimal::qty($row['quantity_received']);
        $damaged = Decimal::qty($row['quantity_damaged'] ?? '0');
        if (! Decimal::isPositive($received) || Decimal::gt($damaged, $received)) {
            throw new BusinessRuleException("Check the received and damaged quantities for {$variant->displayName()}.", 'invalid_quantity');
        }
        $poLine = null;
        if ($order && ! empty($row['purchase_order_line_id'])) {
            $poLine = PurchaseOrderLine::query()->where('purchase_order_id', $order->id)->lockForUpdate()->findOrFail($row['purchase_order_line_id']);
            $open = Decimal::sub($poLine->base_quantity, Decimal::add($poLine->base_quantity_received, $poLine->base_quantity_cancelled));
            $tolerance = Decimal::percentOf($poLine->base_quantity, (string) Settings::get('purchasing.receipt_tolerance_percent'));
            if (Decimal::gt(Decimal::mul($received, $unit->factor), Decimal::add($open, $tolerance))) {
                throw new BusinessRuleException("More {$variant->displayName()} received than is still on order. Record the extra on a separate receipt without the order.", 'over_receipt');
            }
        }
        if (($variant->product->track_expiry || $variant->product->track_batches) && empty($row['batch_no']) && empty($row['expiry_date'])) {
            throw new BusinessRuleException("{$variant->displayName()} is batch-tracked: enter the batch number or expiry date.", 'batch_required');
        }

        $batchId = null;
        if (! empty($row['batch_no']) || ! empty($row['expiry_date'])) {
            $batchNo = $row['batch_no'] ?: 'EXP-'.$row['expiry_date'];
            $batch = StockBatch::query()->firstOrCreate(
                ['variant_id' => $variant->id, 'batch_no' => $batchNo],
                ['expiry_date' => $row['expiry_date'] ?? null, 'supplier_id' => $receipt->supplier_id, 'status' => 'available'],
            );
            if (($row['quality_status'] ?? 'accepted') === 'quarantined') {
                $batch->update(['status' => 'quarantined', 'status_reason' => 'QC hold on '.$receipt->number]);
            }
            $batchId = $batch->id;
        }

        return GoodsReceiptLine::create([
            'goods_receipt_id' => $receipt->id,
            'purchase_order_line_id' => $poLine?->id,
            'variant_id' => $variant->id,
            'variant_unit_id' => $unit->id,
            'unit_factor' => $unit->factor,
            'quantity_expected' => $receipt->is_blind || ! $poLine ? null : Decimal::qty(Decimal::div(Decimal::sub($poLine->base_quantity, $poLine->base_quantity_received), $unit->factor, 3)),
            'quantity_received' => $received,
            'quantity_damaged' => $damaged,
            'base_quantity_accepted' => Decimal::qty(Decimal::mul(Decimal::sub($received, $damaged), $unit->factor)),
            'unit_price' => Decimal::money($row['unit_price']),
            'batch_no' => $row['batch_no'] ?? null,
            'expiry_date' => $row['expiry_date'] ?? null,
            'batch_id' => $batchId,
            'quality_status' => ($row['quality_status'] ?? 'accepted') === 'quarantined' ? 'quarantined' : 'accepted',
            'discrepancy_note' => $row['discrepancy_note'] ?? null,
        ]);
    }

    /**
     * @param  list<GoodsReceiptLine>  $lines
     * @param  list<array{type: string, amount: string, description?: string|null, allocation_method?: string|null}>  $costs
     */
    private function allocateLandedCosts(GoodsReceipt $receipt, array $lines, array $costs): string
    {
        $total = '0';
        foreach ($costs as $cost) {
            if (! Decimal::isPositive($cost['amount'] ?? '0')) {
                continue;
            }
            $method = ($cost['allocation_method'] ?? 'value') === 'quantity' ? 'quantity' : 'value';
            LandedCost::create(['goods_receipt_id' => $receipt->id, 'type' => $cost['type'], 'description' => $cost['description'] ?? null, 'amount' => Decimal::money($cost['amount']), 'allocation_method' => $method]);
            $weights = [];
            foreach ($lines as $i => $line) {
                $weights[$i] = $method === 'quantity'
                    ? Decimal::mul($line->quantity_received, $line->unit_factor)
                    : Decimal::mul($line->quantity_received, $line->unit_price);
            }
            foreach (Decimal::allocate(Decimal::money($cost['amount']), $weights) as $i => $share) {
                $lines[$i]->landed_cost_share = Decimal::money(Decimal::add($lines[$i]->landed_cost_share, $share));
            }
            $total = Decimal::add($total, $cost['amount']);
        }

        return Decimal::money($total);
    }

    /** @param list<GoodsReceiptLine> $lines */
    private function post(User $user, GoodsReceipt $receipt, array $lines, string $landedTotal): GoodsReceipt
    {
        $store = $receipt->store;
        $date = $store->businessDate();
        $context = new StockContext($receipt->getMorphClass(), $receipt->id, $user->id, $date);
        $quarantine = Location::query()->where('store_id', $store->id)->where('type', 'quarantine')->first();
        $damagedLocation = Location::query()->where('store_id', $store->id)->where('type', 'damaged')->first();
        $goodsValue = '0';
        $changes = [];

        foreach ($lines as $line) {
            $baseReceived = Decimal::mul($line->quantity_received, $line->unit_factor);
            $lineValue = Decimal::money(Decimal::mul($line->quantity_received, $line->unit_price));
            $line->base_unit_cost = Decimal::div(Decimal::add($lineValue, $line->landed_cost_share), $baseReceived, 6);
            $line->save();
            $goodsValue = Decimal::add($goodsValue, $lineValue);

            $acceptedLocation = $line->quality_status === 'quarantined' ? $quarantine : $receipt->location;
            if (Decimal::isPositive($line->base_quantity_accepted)) {
                $changes[] = new StockChange($store->id, $acceptedLocation->id, $line->variant_id, $line->base_quantity_accepted, MovementTypes::RECEIPT, $line->batch_id, $line->base_unit_cost, sourceLineId: $line->id);
            }
            $baseDamaged = Decimal::qty(Decimal::mul($line->quantity_damaged, $line->unit_factor));
            if (Decimal::isPositive($baseDamaged)) {
                $changes[] = new StockChange($store->id, $damagedLocation->id, $line->variant_id, $baseDamaged, MovementTypes::RECEIPT, $line->batch_id, $line->base_unit_cost, sourceLineId: $line->id, note: 'Damaged on arrival');
            }
            if ($line->purchase_order_line_id) {
                $poLine = $line->purchaseOrderLine;
                $poLine->update(['base_quantity_received' => Decimal::qty(Decimal::add($poLine->base_quantity_received, $baseReceived))]);
            }
        }
        $movements = $this->ledger->post($changes, $context);
        $inventoryValue = Decimal::sum(array_map(fn ($m) => $m->total_cost ?? '0', $movements));

        $this->journals->post('goods_receipt', $receipt, $date, "Goods received {$receipt->number}", [
            ['account' => 'inventory', 'debit' => $inventoryValue],
            ['account' => 'grni', 'credit' => $inventoryValue, 'contact_id' => $receipt->supplier_id],
        ], $store->id);

        $receipt->update(['status' => 'posted', 'goods_value' => Decimal::money($goodsValue), 'landed_cost_total' => $landedTotal, 'posted_by' => $user->id, 'business_date' => $date]);
        if ($receipt->purchase_order_id) {
            $this->refreshOrderStatus($receipt->purchaseOrder);
        }
        // Remember which supplier sells which product in which pack, for reorder suggestions and purchase orders.
        foreach ($receipt->lines()->get(['variant_id', 'variant_unit_id']) as $line) {
            \App\Models\SupplierProduct::query()->firstOrCreate(
                ['supplier_id' => $receipt->supplier_id, 'variant_id' => $line->variant_id, 'variant_unit_id' => $line->variant_unit_id],
                ['is_preferred' => ! \App\Models\SupplierProduct::query()->where('variant_id', $line->variant_id)->exists()],
            );
        }
        Audit::record('purchasing.goods_received', "Received {$receipt->number} from {$receipt->supplier->name}: Rs {$goodsValue} + landed Rs {$landedTotal}", $receipt);

        return $receipt->fresh('lines');
    }

    private function refreshOrderStatus(PurchaseOrder $order): void
    {
        $order->load('lines');
        $complete = $order->lines->every(fn ($l) => Decimal::gte(Decimal::add($l->base_quantity_received, $l->base_quantity_cancelled), $l->base_quantity));
        $order->update(['status' => $complete ? 'received' : 'partially_received']);
    }
}
