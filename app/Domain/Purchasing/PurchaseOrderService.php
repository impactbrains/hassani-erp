<?php

namespace App\Domain\Purchasing;

use App\Exceptions\BusinessRuleException;
use App\Models\Contact;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\Store;
use App\Models\User;
use App\Models\VariantUnit;
use App\Support\Audit;
use App\Support\Decimal;
use App\Support\Sequences;
use App\Support\Settings\Settings;
use Illuminate\Support\Facades\DB;

/**
 * Purchase orders: draft -> submitted (requisition) -> approved -> sent ->
 * partially_received -> received -> closed, or cancelled. Orders above the
 * approval amount need someone other than their creator to approve them.
 */
final class PurchaseOrderService
{
    /** @param array{supplier_id: int, expected_date?: string|null, notes?: string|null, source?: string, lines: list<array{variant_id: int, variant_unit_id: int, quantity: string, unit_price: string, tax_rate?: string|null}>} $data */
    public function save(User $user, Store $store, array $data, ?PurchaseOrder $order = null): PurchaseOrder
    {
        if (! $user->hasPermission('purchasing.orders.manage', $store->id)) {
            throw new BusinessRuleException('You are not allowed to create purchase orders.', 'not_permitted');
        }
        if ($order && ! in_array($order->status, ['draft', 'submitted'], true)) {
            throw new BusinessRuleException('Only draft or submitted orders can be edited.', 'invalid_status');
        }
        $supplier = Contact::query()->ofType('supplier')->findOrFail($data['supplier_id']);
        if (empty($data['lines'])) {
            throw new BusinessRuleException('Add at least one product.', 'no_lines');
        }

        return DB::transaction(function () use ($user, $store, $data, $order, $supplier) {
            $order ??= new PurchaseOrder([
                'store_id' => $store->id,
                'number' => Sequences::next('purchase_order', $store->id, $store->code),
                'status' => 'draft',
                'source' => $data['source'] ?? 'manual',
                'order_date' => $store->businessDate(),
                'created_by' => $user->id,
            ]);
            $order->fill(['supplier_id' => $supplier->id, 'expected_date' => $data['expected_date'] ?? null, 'notes' => $data['notes'] ?? null]);
            $order->save();
            $order->lines()->delete();

            $subtotal = '0';
            $tax = '0';
            foreach ($data['lines'] as $line) {
                $unit = VariantUnit::query()->where('variant_id', $line['variant_id'])->findOrFail($line['variant_unit_id']);
                if (! Decimal::isPositive($line['quantity'])) {
                    continue;
                }
                $lineValue = Decimal::money(Decimal::mul($line['quantity'], $line['unit_price']));
                $lineTax = Decimal::percentOf($lineValue, $line['tax_rate'] ?? '0');
                PurchaseOrderLine::create([
                    'purchase_order_id' => $order->id,
                    'variant_id' => $line['variant_id'],
                    'variant_unit_id' => $unit->id,
                    'quantity' => Decimal::qty($line['quantity']),
                    'unit_factor' => $unit->factor,
                    'base_quantity' => Decimal::qty(Decimal::mul($line['quantity'], $unit->factor)),
                    'unit_price' => Decimal::money($line['unit_price']),
                    'tax_rate' => $line['tax_rate'] ?? '0',
                    'line_total' => $lineValue,
                ]);
                $subtotal = Decimal::add($subtotal, $lineValue);
                $tax = Decimal::add($tax, $lineTax);
            }
            $order->update(['subtotal' => Decimal::money($subtotal), 'tax_total' => Decimal::money($tax), 'total' => Decimal::money(Decimal::add($subtotal, $tax))]);
            Audit::record('purchasing.po_saved', "Saved purchase order {$order->number} for Rs {$order->total}", $order);

            return $order->fresh('lines');
        });
    }

    public function submit(User $user, PurchaseOrder $order): PurchaseOrder
    {
        $this->expect($order, ['draft']);
        $order->update(['status' => 'submitted']);
        Audit::record('purchasing.po_submitted', "Submitted {$order->number} for approval", $order);

        return $this->canApprove($user, $order) ? $this->approve($user, $order) : $order;
    }

    public function approve(User $user, PurchaseOrder $order): PurchaseOrder
    {
        $this->expect($order, ['draft', 'submitted']);
        if (! $this->canApprove($user, $order)) {
            throw new BusinessRuleException('This order needs approval by someone with purchase approval rights other than its creator.', 'approval_required');
        }
        $order->update(['status' => 'approved', 'approved_by' => $user->id, 'approved_at' => now()]);
        Audit::record('purchasing.po_approved', "Approved {$order->number} (Rs {$order->total})", $order);

        return $order;
    }

    public function markSent(User $user, PurchaseOrder $order): PurchaseOrder
    {
        $this->expect($order, ['approved']);
        $order->update(['status' => 'sent', 'sent_at' => now()]);
        Audit::record('purchasing.po_sent', "Sent {$order->number} to supplier", $order);

        return $order;
    }

    public function cancel(User $user, PurchaseOrder $order, string $reason): PurchaseOrder
    {
        if (! $user->hasPermission('purchasing.orders.manage', $order->store_id)) {
            throw new BusinessRuleException('You are not allowed to cancel purchase orders.', 'not_permitted');
        }
        if (in_array($order->status, ['partially_received', 'received', 'closed', 'cancelled'], true)) {
            if ($order->status === 'partially_received') {
                return $this->close($user, $order, $reason);
            }
            throw new BusinessRuleException('This order can no longer be cancelled.', 'invalid_status');
        }
        $order->update(['status' => 'cancelled', 'notes' => trim(($order->notes ?? '')."\nCancelled: {$reason}")]);
        Audit::record('purchasing.po_cancelled', "Cancelled {$order->number}: {$reason}", $order);

        return $order;
    }

    /** Closes a partly received order; the outstanding quantity becomes a cancelled backorder. */
    public function close(User $user, PurchaseOrder $order, string $reason): PurchaseOrder
    {
        DB::transaction(function () use ($order) {
            foreach ($order->lines as $line) {
                $open = Decimal::sub($line->base_quantity, Decimal::add($line->base_quantity_received, $line->base_quantity_cancelled));
                if (Decimal::isPositive($open)) {
                    $line->update(['base_quantity_cancelled' => Decimal::qty(Decimal::add($line->base_quantity_cancelled, $open))]);
                }
            }
            $order->update(['status' => 'closed', 'closed_at' => now()]);
        });
        Audit::record('purchasing.po_closed', "Closed {$order->number}: {$reason}", $order);

        return $order;
    }

    public function canApprove(User $user, PurchaseOrder $order): bool
    {
        if (! $user->hasPermission('purchasing.orders.approve', $order->store_id)) {
            return false;
        }
        if ($user->isOwner() || $order->created_by !== $user->id) {
            return true;
        }

        return Decimal::lte($order->total, (string) Settings::get('purchasing.approval_amount', $order->store_id));
    }

    private function expect(PurchaseOrder $order, array $statuses): void
    {
        if (! in_array($order->status, $statuses, true)) {
            throw new BusinessRuleException("Purchase order {$order->number} is {$order->status}.", 'invalid_status');
        }
    }
}
