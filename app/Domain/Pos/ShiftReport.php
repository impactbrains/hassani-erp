<?php

namespace App\Domain\Pos;

use App\Models\AuditLog;
use App\Models\CashMovement;
use App\Models\CreditNote;
use App\Models\Payment;
use App\Models\SalesInvoice;
use App\Models\Shift;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;

/**
 * Builds the X/Z report for a shift from posted records only. The same
 * function produces the live X-Report and the frozen Z-Report snapshot, so
 * the two can never disagree about how a figure is calculated.
 */
final class ShiftReport
{
    /** @return array<string, mixed> */
    public function build(Shift $shift): array
    {
        $shift->loadMissing('register', 'store', 'opener');
        $invoices = SalesInvoice::query()->where('shift_id', $shift->id)->where('status', 'posted');
        $sales = (clone $invoices)->selectRaw('COUNT(*) n, COALESCE(SUM(subtotal),0) gross, COALESCE(SUM(discount_total),0) discounts, COALESCE(SUM(tax_total),0) tax, COALESCE(SUM(rounding_adjustment),0) rounding, COALESCE(SUM(total),0) total, COALESCE(SUM(credit_extended),0) khata')->first();
        $returns = CreditNote::query()->where('shift_id', $shift->id)->selectRaw('COUNT(*) n, COALESCE(SUM(total),0) total, COALESCE(SUM(tax_total),0) tax')->first();

        $tenders = Payment::query()
            ->join('payment_methods', 'payment_methods.id', '=', 'payments.payment_method_id')
            ->where('payments.shift_id', $shift->id)
            ->groupBy('payment_methods.id', 'payment_methods.name', 'payment_methods.type', 'payments.direction', 'payments.kind', 'payments.terminal_label')
            ->orderBy('payment_methods.name')
            ->get([
                'payment_methods.name', 'payment_methods.type', 'payments.direction', 'payments.kind', 'payments.terminal_label',
                DB::raw('COUNT(*) as count'), DB::raw('SUM(payments.amount) as amount'),
                DB::raw("SUM(CASE WHEN payments.verification_status = 'pending' THEN payments.amount ELSE 0 END) as pending"),
            ]);

        $byMethod = [];
        foreach ($tenders as $row) {
            $key = $row->name.($row->terminal_label ? ' · '.$row->terminal_label : '');
            $byMethod[$key] ??= ['method' => $row->name, 'terminal' => $row->terminal_label, 'type' => $row->type, 'sales' => '0.00', 'collections' => '0.00', 'refunds' => '0.00', 'pending_verification' => '0.00', 'count' => 0];
            $bucket = $row->direction === 'out' ? 'refunds' : ($row->kind === 'collection' ? 'collections' : 'sales');
            $byMethod[$key][$bucket] = Decimal::money(Decimal::add($byMethod[$key][$bucket], $row->amount));
            $byMethod[$key]['pending_verification'] = Decimal::money(Decimal::add($byMethod[$key]['pending_verification'], $row->pending));
            $byMethod[$key]['count'] += (int) $row->count;
        }

        $cash = $this->cashFigures($shift);
        $movements = CashMovement::query()->where('shift_id', $shift->id)->with('reasonCode:id,label', 'user:id,name', 'approver:id,name')->orderBy('occurred_at')->get();

        $auditEvents = AuditLog::query()
            ->where('store_id', $shift->store_id)
            ->where('created_at', '>=', $shift->opened_at)
            ->when($shift->closed_at, fn ($q) => $q->where('created_at', '<=', $shift->closed_at))
            ->whereIn('event', ['pos.line_removed', 'pos.price_override', 'pos.discount', 'pos.credit_override', 'pos.cart_discarded', 'approval.granted', 'approval.pin_failed'])
            ->where(fn ($q) => $q->where('user_id', $shift->opened_by)->orWhere('event', 'approval.granted'))
            ->groupBy('event')
            ->selectRaw('event, COUNT(*) AS n')
            ->pluck('n', 'event');

        return [
            'shift' => [
                'number' => $shift->number, 'register' => $shift->register?->name, 'store' => $shift->store?->name,
                'cashier' => $shift->opener?->name, 'opened_at' => $shift->opened_at?->toIso8601String(),
                'business_date' => $shift->business_date?->toDateString(), 'status' => $shift->status,
                'generated_at' => now()->toIso8601String(),
            ],
            'sales' => [
                'count' => (int) $sales->n,
                'gross' => Decimal::money($sales->gross),
                'discounts' => Decimal::money($sales->discounts),
                'tax' => Decimal::money($sales->tax),
                'rounding' => Decimal::money($sales->rounding),
                'total' => Decimal::money($sales->total),
                'returns_count' => (int) $returns->n,
                'returns' => Decimal::money($returns->total),
                'net' => Decimal::money(Decimal::sub($sales->total, $returns->total)),
                'khata_extended' => Decimal::money($sales->khata),
            ],
            'tenders' => array_values($byMethod),
            'cash' => $cash,
            'cash_movements' => $movements->map(fn (CashMovement $m) => [
                'number' => $m->number, 'type' => $m->type, 'amount' => $m->amount, 'reason' => $m->reasonCode?->label,
                'recipient' => $m->recipient, 'note' => $m->note, 'by' => $m->user?->name, 'approved_by' => $m->approver?->name,
                'at' => $m->occurred_at?->toIso8601String(),
            ])->all(),
            'exceptions' => [
                'line_removals' => (int) ($auditEvents['pos.line_removed'] ?? 0),
                'price_overrides' => (int) ($auditEvents['pos.price_override'] ?? 0),
                'discounted_sales' => (int) ($auditEvents['pos.discount'] ?? 0),
                'khata_limit_exceptions' => (int) ($auditEvents['pos.credit_override'] ?? 0),
                'discarded_carts' => (int) ($auditEvents['pos.cart_discarded'] ?? 0),
                'failed_pin_attempts' => (int) ($auditEvents['approval.pin_failed'] ?? 0),
                'no_sale_openings' => $movements->where('type', 'no_sale')->count(),
                'payouts' => $movements->where('type', 'payout')->count(),
            ],
        ];
    }

    public function expectedCash(Shift $shift): string
    {
        return $this->cashFigures($shift)['expected'];
    }

    /** @return array{opening_float: string, cash_in: string, cash_refunds: string, payouts: string, drops: string, pay_ins: string, expected: string, formula: string} */
    private function cashFigures(Shift $shift): array
    {
        $cash = Payment::query()
            ->join('payment_methods', 'payment_methods.id', '=', 'payments.payment_method_id')
            ->where('payments.shift_id', $shift->id)
            ->where('payment_methods.type', 'cash')
            ->where('payments.custody', 'drawer')
            ->selectRaw("COALESCE(SUM(CASE WHEN payments.direction = 'in' THEN payments.amount ELSE 0 END),0) cash_in, COALESCE(SUM(CASE WHEN payments.direction = 'out' THEN payments.amount ELSE 0 END),0) cash_out")
            ->first();
        $movements = CashMovement::query()->where('shift_id', $shift->id)
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'payout' THEN amount ELSE 0 END),0) payouts, COALESCE(SUM(CASE WHEN type = 'drop' THEN amount ELSE 0 END),0) drops, COALESCE(SUM(CASE WHEN type = 'pay_in' THEN amount ELSE 0 END),0) pay_ins")
            ->first();

        $expected = Decimal::add($shift->opening_float, $cash->cash_in, $movements->pay_ins);
        $expected = Decimal::sub($expected, Decimal::add($cash->cash_out, $movements->payouts, $movements->drops));

        return [
            'opening_float' => Decimal::money($shift->opening_float),
            'cash_in' => Decimal::money($cash->cash_in),
            'cash_refunds' => Decimal::money($cash->cash_out),
            'payouts' => Decimal::money($movements->payouts),
            'drops' => Decimal::money($movements->drops),
            'pay_ins' => Decimal::money($movements->pay_ins),
            'expected' => Decimal::money($expected),
            'formula' => 'Opening float + cash received + pay-ins − cash refunds − payouts − drops',
        ];
    }
}
