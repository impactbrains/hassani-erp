<?php

namespace App\Domain\Reporting;

use App\Support\Decimal;
use Illuminate\Support\Facades\DB;

/**
 * The single definition of headline sales figures (dashboard, summary,
 * end-of-day). See docs/metric-dictionary.md.
 *
 *  gross sales   = line amounts before discounts
 *  discounts     = line + bill discounts
 *  returns       = credit-note totals dated in the period
 *  net sales     = gross - discounts - returns (excludes rounding and delivery charges)
 *  net revenue   = revenue ex tax of sales - of returns (= GL Sales - Sales returns)
 *  cost of sales = COGS - cost of goods returned to stock
 *  gross margin  = net revenue - cost of sales (flagged when a cost was unknown)
 *  average basket= (gross - discounts) / transactions
 *  collected     = money in (sales, Wasooli, COD) - refunds; rejected wallet payments excluded
 */
final class SalesMetrics
{
    /** @return array<string, string|int|bool|null> */
    public static function totals(ReportFilters $f): array
    {
        $inv = DB::table('sales_invoices')->where('status', 'posted')->whereBetween('business_date', [$f->from, $f->to])->whereIn('store_id', $f->storeIds)
            ->when($f->entity('customer_id'), fn ($q, $id) => $q->where('customer_id', $id))
            ->when($f->entity('cashier_id'), fn ($q, $id) => $q->where('cashier_id', $id))
            ->selectRaw('COUNT(*) transactions, COALESCE(SUM(subtotal),0) gross, COALESCE(SUM(discount_total),0) discounts, COALESCE(SUM(tax_total),0) tax,
                COALESCE(SUM(cost_total),0) cost, COALESCE(SUM(credit_extended),0) khata, COALESCE(SUM(rounding_adjustment),0) rounding,
                COALESCE(SUM(delivery_charge),0) delivery, BOOL_AND(cost_complete) cost_complete')->first();
        $revenue = DB::table('sales_invoice_lines')->join('sales_invoices', 'sales_invoices.id', '=', 'sales_invoice_lines.sales_invoice_id')
            ->where('sales_invoices.status', 'posted')->whereBetween('sales_invoices.business_date', [$f->from, $f->to])->whereIn('sales_invoices.store_id', $f->storeIds)
            ->when($f->entity('customer_id'), fn ($q, $id) => $q->where('sales_invoices.customer_id', $id))
            ->when($f->entity('cashier_id'), fn ($q, $id) => $q->where('sales_invoices.cashier_id', $id))
            ->selectRaw('COALESCE(SUM(sales_invoice_lines.revenue_amount),0) r')->value('r');
        $ret = DB::table('credit_notes')->whereBetween('business_date', [$f->from, $f->to])->whereIn('store_id', $f->storeIds)
            ->when($f->entity('customer_id'), fn ($q, $id) => $q->where('customer_id', $id))
            ->selectRaw('COUNT(*) n, COALESCE(SUM(total),0) total, COALESCE(SUM(subtotal),0) revenue, COALESCE(SUM(tax_total),0) tax, COALESCE(SUM(cost_total),0) cost')->first();
        $collected = DB::table('payments')->whereBetween('business_date', [$f->from, $f->to])->whereIn('store_id', $f->storeIds)
            ->whereIn('kind', ['sale', 'collection', 'delivery_collection', 'refund'])->where('verification_status', '!=', 'rejected')
            ->when($f->entity('customer_id'), fn ($q, $id) => $q->where('contact_id', $id))
            ->selectRaw("COALESCE(SUM(CASE WHEN direction='in' THEN amount ELSE -amount END),0) net")->value('net');

        $net = Decimal::money(Decimal::sub(Decimal::sub($inv->gross, $inv->discounts), $ret->total));
        $netRevenue = Decimal::money(Decimal::sub($revenue ?? '0', $ret->revenue));
        $cos = Decimal::money(Decimal::sub($inv->cost, $ret->cost));
        $margin = Decimal::money(Decimal::sub($netRevenue, $cos));
        $n = (int) $inv->transactions;
        $afterDiscount = Decimal::sub($inv->gross, $inv->discounts);

        return [
            'transactions' => $n,
            'gross_sales' => Decimal::money($inv->gross),
            'discounts' => Decimal::money($inv->discounts),
            'returns' => Decimal::money($ret->total),
            'returns_count' => (int) $ret->n,
            'net_sales' => $net,
            'net_revenue' => $netRevenue,
            'tax' => Decimal::money(Decimal::sub($inv->tax, $ret->tax)),
            'cost_of_sales' => $cos,
            'gross_margin' => $margin,
            'gross_margin_percent' => Decimal::isPositive($netRevenue) ? Decimal::round(Decimal::div(Decimal::mul($margin, '100'), $netRevenue, 4), 2) : null,
            'cost_complete' => $inv->cost_complete === null ? true : (bool) $inv->cost_complete,
            'average_basket' => $n > 0 ? Decimal::money(Decimal::div($afterDiscount, (string) $n, 4)) : '0.00',
            'collected' => Decimal::money($collected ?? '0'),
            'khata_extended' => Decimal::money($inv->khata),
            'rounding' => Decimal::money($inv->rounding),
            'delivery_charges' => Decimal::money($inv->delivery),
            'discount_rate' => Decimal::isPositive($inv->gross) ? Decimal::round(Decimal::div(Decimal::mul($inv->discounts, '100'), $inv->gross, 4), 2) : null,
            'return_rate' => Decimal::isPositive($afterDiscount) ? Decimal::round(Decimal::div(Decimal::mul($ret->total, '100'), $afterDiscount, 4), 2) : null,
        ];
    }

    /** Percent change; null when the base is zero (shown as "new", never infinite growth). */
    public static function change(string|int|null $current, string|int|null $previous): ?string
    {
        if ($previous === null || Decimal::isZero((string) $previous)) {
            return null;
        }

        return Decimal::round(Decimal::div(Decimal::mul(Decimal::sub((string) $current, (string) $previous), '100'), Decimal::abs((string) $previous), 4), 1);
    }
}
