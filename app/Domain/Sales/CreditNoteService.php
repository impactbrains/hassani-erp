<?php

namespace App\Domain\Sales;

use App\Domain\Accounting\JournalPoster;
use App\Domain\Approvals\ApprovalService;
use App\Domain\Inventory\MovementTypes;
use App\Domain\Inventory\StockChange;
use App\Domain\Inventory\StockContext;
use App\Domain\Inventory\StockLedger;
use App\Exceptions\BusinessRuleException;
use App\Models\BundleComponent;
use App\Models\CreditNote;
use App\Models\CreditNoteLine;
use App\Models\Location;
use App\Models\PaymentMethod;
use App\Models\ReasonCode;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceLine;
use App\Models\Shift;
use App\Models\User;
use App\Support\Audit;
use App\Support\Decimal;
use App\Support\Sequences;
use App\Support\Settings\Settings;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Returns and refunds. A credit note always points at the original sale and
 * can only return what was sold and not already returned. Amounts are the
 * original line amounts in proportion (the receipt's rounding adjustment is
 * not refunded). Returned goods go back on the shelf, to quarantine or to
 * the damaged area at their original cost. The money goes back in cash or
 * another tender from the open shift, or as a credit on the customer's Khata.
 */
final class CreditNoteService
{
    public function __construct(
        private readonly StockLedger $ledger,
        private readonly ApprovalService $approvals,
        private readonly PaymentRecorder $payments,
        private readonly JournalPoster $journals,
    ) {
    }

    /**
     * @param  array{lines: list<array{invoice_line_id: int, quantity: string, disposition: string}>, reason_code_id: int, settlement: string,
     *     payment_method_id?: int|null, shift_id?: int|null, approval_token?: string|null, idempotency_key?: string|null, notes?: string|null}  $input
     */
    public function create(User $user, SalesInvoice $invoice, array $input): CreditNote
    {
        if (! empty($input['idempotency_key']) && ($existing = CreditNote::query()->where('idempotency_key', $input['idempotency_key'])->first())) {
            return $existing;
        }
        if (! $user->hasPermission('pos.refund', $invoice->store_id) && ! $user->hasPermission('sales.credit_notes.create', $invoice->store_id)) {
            throw new BusinessRuleException('You are not allowed to process returns.', 'refund_not_permitted');
        }

        try {
            return DB::transaction(fn () => $this->post($user, $invoice, $input));
        } catch (UniqueConstraintViolationException $e) {
            if (! empty($input['idempotency_key']) && ($existing = CreditNote::query()->where('idempotency_key', $input['idempotency_key'])->first())) {
                return $existing;
            }
            throw $e;
        }
    }

    /** @param array<string, mixed> $input */
    private function post(User $user, SalesInvoice $invoice, array $input): CreditNote
    {
        $invoice = SalesInvoice::query()->lockForUpdate()->findOrFail($invoice->id);
        $store = $invoice->store;
        $date = $store->businessDate();
        $reason = ReasonCode::query()->where('type', 'return')->find($input['reason_code_id']);
        if ($reason === null) {
            throw new BusinessRuleException('Choose a return reason.', 'reason_required', field: 'reason_code_id');
        }
        $settlement = $input['settlement'];
        if (! in_array($settlement, ['refund', 'khata_credit'], true)) {
            throw new BusinessRuleException('Choose how the customer gets their money back.', 'invalid_settlement');
        }
        if ($settlement === 'khata_credit' && $invoice->customer_id === null) {
            throw new BusinessRuleException('Only a named customer can get a Khata credit. Give a refund instead.', 'khata_needs_customer');
        }

        $computed = $this->computeLines($invoice, $input['lines']);
        $total = Decimal::money(Decimal::sum(array_column($computed, 'line_total')));
        if (! Decimal::isPositive($total)) {
            throw new BusinessRuleException('Choose at least one item to return.', 'nothing_to_return');
        }

        $approval = null;
        if (Decimal::gt($total, (string) Settings::get('pos.refund_approval_amount', $store->id))
            && ! $this->approvals->canSelfApprove($user, 'refund', $store->id)) {
            $approval = $this->approvals->consume($input['approval_token'] ?? null, 'refund', $store->id, $user);
        }

        $shift = null;
        $method = null;
        if ($settlement === 'refund') {
            $method = PaymentMethod::query()->where('is_active', true)->find($input['payment_method_id'] ?? null)
                ?? PaymentMethod::query()->where('code', 'cash')->firstOrFail();
            if ($method->type === 'cash') {
                $shift = Shift::query()->where('status', 'open')->find($input['shift_id'] ?? null);
                if ($shift === null || $shift->store_id !== $store->id) {
                    throw new BusinessRuleException('Open a shift to pay a cash refund from the drawer.', 'shift_not_open');
                }
                $available = app(\App\Domain\Pos\ShiftReport::class)->expectedCash($shift);
                if (Decimal::gt($total, $available)) {
                    throw new BusinessRuleException("The drawer should only hold Rs {$available}.", 'not_enough_cash');
                }
            }
        }

        $creditNote = CreditNote::create([
            'store_id' => $store->id,
            'number' => Sequences::next('credit_note', $store->id, $store->code),
            'sales_invoice_id' => $invoice->id,
            'customer_id' => $invoice->customer_id,
            'reason_code_id' => $reason->id,
            'settlement' => $settlement,
            'subtotal' => Decimal::money(Decimal::sum(array_column($computed, 'revenue_amount'))),
            'tax_total' => Decimal::money(Decimal::sum(array_column($computed, 'tax_amount'))),
            'total' => $total,
            'cost_total' => Decimal::money(Decimal::sum(array_map(fn ($c) => $c['cost_amount'] ?? '0', $computed))),
            'shift_id' => $shift?->id,
            'register_id' => $shift?->register_id,
            'approval_id' => $approval?->id,
            'business_date' => $date,
            'posted_by' => $user->id,
            'posted_at' => now(),
            'idempotency_key' => $input['idempotency_key'] ?? null,
            'notes' => $input['notes'] ?? null,
        ]);

        $context = new StockContext($creditNote->getMorphClass(), $creditNote->id, $user->id, $date, approvedBy: $approval?->approved_by);
        $restockedCost = '0';
        foreach ($computed as $row) {
            /** @var SalesInvoiceLine $invoiceLine */
            $invoiceLine = $row['invoice_line'];
            $location = $this->dispositionLocation($store->id, $row['disposition'], $invoice->register?->location_id);
            $creditLine = CreditNoteLine::create([
                'credit_note_id' => $creditNote->id,
                'sales_invoice_line_id' => $invoiceLine->id,
                'variant_id' => $invoiceLine->variant_id,
                'quantity' => $row['quantity'],
                'base_quantity' => $row['base_quantity'],
                'tax_amount' => $row['tax_amount'],
                'revenue_amount' => $row['revenue_amount'],
                'line_total' => $row['line_total'],
                'cost_amount' => $row['cost_amount'],
                'disposition' => $row['disposition'],
                'location_id' => $location?->id,
            ]);
            $invoiceLine->base_quantity_returned = Decimal::qty(Decimal::add($invoiceLine->base_quantity_returned, $row['base_quantity']));
            $invoiceLine->save();

            if ($location !== null && $row['cost_amount'] !== null) {
                $restockedCost = Decimal::add($restockedCost, $this->returnStock($invoiceLine, $row, $location, $creditLine->id, $context));
            }
        }

        $this->settle($user, $creditNote, $invoice, $settlement, $method, $shift, $date);
        $this->postJournals($creditNote, $invoice, $settlement, $method, $restockedCost, $date);

        Audit::record('sales.credit_note', "Return {$creditNote->number} of Rs {$total} against {$invoice->number}", $creditNote, new: ['settlement' => $settlement, 'approval_id' => $approval?->id]);

        return $creditNote->load('lines', 'invoice');
    }

    /**
     * @param  list<array{invoice_line_id: int, quantity: string, disposition: string}>  $requested
     * @return list<array<string, mixed>>
     */
    private function computeLines(SalesInvoice $invoice, array $requested): array
    {
        $result = [];
        foreach ($requested as $row) {
            if (! Decimal::isPositive($row['quantity'] ?? '0')) {
                continue;
            }
            /** @var SalesInvoiceLine|null $line */
            $line = SalesInvoiceLine::query()->where('sales_invoice_id', $invoice->id)->lockForUpdate()->find($row['invoice_line_id']);
            if ($line === null) {
                throw new BusinessRuleException('That item is not on this receipt.', 'line_not_on_invoice');
            }
            $baseQuantity = Decimal::qty(Decimal::mul($row['quantity'], $line->unit_factor));
            $returnable = Decimal::sub($line->base_quantity, $line->base_quantity_returned);
            if (Decimal::gt($baseQuantity, $returnable)) {
                throw new BusinessRuleException("Only ".Decimal::trim(Decimal::div($returnable, $line->unit_factor, 3))." of {$line->description} can still be returned.", 'return_exceeds_sold');
            }
            if (! in_array($row['disposition'], ['restock', 'damaged', 'quarantine', 'none'], true)) {
                throw new BusinessRuleException('Choose what happens to the returned item.', 'invalid_disposition');
            }
            $isLastReturn = Decimal::eq($baseQuantity, $returnable);
            $share = fn (string $amount, string $alreadyReturned) => $isLastReturn
                ? Decimal::money(Decimal::sub($amount, $alreadyReturned))
                : Decimal::money(Decimal::div(Decimal::mul($amount, $baseQuantity), $line->base_quantity, 10));
            $previous = CreditNoteLine::query()->where('sales_invoice_line_id', $line->id)
                ->selectRaw('COALESCE(SUM(line_total),0) t, COALESCE(SUM(tax_amount),0) x, COALESCE(SUM(revenue_amount),0) r, COALESCE(SUM(cost_amount),0) c')->first();

            $result[] = [
                'invoice_line' => $line,
                'quantity' => Decimal::qty($row['quantity']),
                'base_quantity' => $baseQuantity,
                'disposition' => $row['disposition'],
                'line_total' => $share($line->line_total, $previous->t),
                'tax_amount' => $share($line->tax_amount, $previous->x),
                'revenue_amount' => $share($line->revenue_amount, $previous->r),
                'cost_amount' => $line->cost_amount === null ? null : $share($line->cost_amount, $previous->c),
            ];
        }

        return $result;
    }

    private function dispositionLocation(int $storeId, string $disposition, ?int $registerLocationId): ?Location
    {
        return match ($disposition) {
            'restock' => Location::query()->find($registerLocationId) ?? Location::query()->where('store_id', $storeId)->where('is_default_selling', true)->first(),
            'damaged' => Location::query()->where('store_id', $storeId)->where('type', 'damaged')->first(),
            'quarantine' => Location::query()->where('store_id', $storeId)->where('type', 'quarantine')->first(),
            default => null,
        };
    }

    /** @param array<string, mixed> $row @return string cost put back into inventory */
    private function returnStock(SalesInvoiceLine $invoiceLine, array $row, Location $location, int $creditLineId, \App\Domain\Inventory\StockContext $context): string
    {
        $variant = $invoiceLine->variant()->with('product')->first();
        if (! $variant->product->isStocked()) {
            return '0';
        }
        $parts = $variant->isBundle()
            ? BundleComponent::query()->where('bundle_variant_id', $variant->id)->get()->map(fn ($c) => [$c->component_variant_id, Decimal::qty(Decimal::mul($c->quantity, $row['base_quantity'])), MovementTypes::BUNDLE_RETURN])->all()
            : [[$variant->id, $row['base_quantity'], MovementTypes::SALE_RETURN]];

        $totalUnits = Decimal::sum(array_column($parts, 1));
        $changes = [];
        foreach ($parts as [$variantId, $quantity, $type]) {
            $unitCost = Decimal::isPositive($totalUnits) ? Decimal::div($row['cost_amount'], $totalUnits, 6) : '0';
            $changes[] = new StockChange($location->store_id, $location->id, $variantId, $quantity, $type, unitCost: $unitCost, sourceLineId: $creditLineId);
        }
        $movements = $this->ledger->post($changes, $context);

        return Decimal::sum(array_map(fn ($m) => $m->total_cost ?? '0', $movements));
    }

    private function settle(User $user, CreditNote $creditNote, SalesInvoice $invoice, string $settlement, ?PaymentMethod $method, ?Shift $shift, string $date): void
    {
        if ($settlement === 'refund') {
            $this->payments->record($method, $creditNote->total, [
                'store_id' => $creditNote->store_id,
                'direction' => 'out',
                'kind' => 'refund',
                'contact_id' => $invoice->customer_id,
                'shift_id' => $shift?->id,
                'register_id' => $shift?->register_id,
                'received_by' => $user->id,
                'business_date' => $date,
                'custody' => $method->type === 'cash' ? 'drawer' : $method->type,
            ], [[$creditNote, $creditNote->total]]);
            $invoice->amount_paid = Decimal::money(Decimal::sub($invoice->amount_paid, $creditNote->total));
        }
        $invoice->amount_credited = Decimal::money(Decimal::add($invoice->amount_credited, $creditNote->total));
        $balance = $invoice->balanceDue();
        $invoice->payment_status = Decimal::lte($balance, '0') ? 'paid' : (Decimal::gt($invoice->amount_paid, '0') || Decimal::gt($invoice->amount_credited, '0') ? 'partial' : 'unpaid');
        $invoice->save();
    }

    private function postJournals(CreditNote $creditNote, SalesInvoice $invoice, string $settlement, ?PaymentMethod $method, string $restockedCost, string $date): void
    {
        $creditAccount = $settlement === 'refund' ? $method->account_id : 'accounts_receivable';
        $this->journals->post('credit_note', $creditNote, $date, "Return {$creditNote->number} on {$invoice->number}", [
            ['account' => 'sales_returns', 'debit' => $creditNote->subtotal],
            ['account' => 'sales_tax_payable', 'debit' => $creditNote->tax_total],
            ['account' => $creditAccount, 'credit' => $creditNote->total, 'contact_id' => $invoice->customer_id],
        ], $creditNote->store_id);
        $this->journals->post('cogs_reversal', $creditNote, $date, "Stock returned on {$creditNote->number}", [
            ['account' => 'inventory', 'debit' => $restockedCost],
            ['account' => 'cogs', 'credit' => $restockedCost],
        ], $creditNote->store_id);
    }
}
