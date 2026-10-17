<?php

namespace App\Domain\Reporting;

use App\Models\User;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;

/**
 * Home dashboard. Answers the five questions an owner asks every day:
 * how much did we sell today, what sold best, how did people pay, did every
 * shift close correctly, and do the stock counts match. Money figures are
 * only included for users allowed to see them.
 */
final class DashboardService
{
    /** @return array<string, mixed> */
    public function build(User $user, ReportFilters $f): array
    {
        $financials = $user->hasPermission('dashboard.view_financials');
        $data = ['period' => ['from' => $f->from, 'to' => $f->to, 'compare_from' => $f->compareFrom, 'compare_to' => $f->compareTo], 'financials' => $financials];

        $now = SalesMetrics::totals($f);
        $prev = $f->comparison() ? SalesMetrics::totals($f->comparison()) : null;
        $data['sales'] = $financials ? array_map(fn ($key) => ['key' => $key, 'value' => $now[$key], 'previous' => $prev[$key] ?? null, 'change' => $prev ? SalesMetrics::change($now[$key], $prev[$key]) : null],
            ['net_sales', 'transactions', 'average_basket', 'gross_margin', 'collected', 'khata_extended', 'returns', 'discounts']) : [
                ['key' => 'transactions', 'value' => $now['transactions'], 'previous' => $prev['transactions'] ?? null, 'change' => $prev ? SalesMetrics::change($now['transactions'], $prev['transactions']) : null],
            ];
        $data['cost_complete'] = $now['cost_complete'];

        $data['hourly'] = DB::table('sales_invoices')->where('status', 'posted')->whereBetween('business_date', [$f->from, $f->to])->whereIn('store_id', $f->storeIds)
            ->groupByRaw("EXTRACT(HOUR FROM posted_at AT TIME ZONE '{$f->timezone}')")->orderByRaw('1')
            ->selectRaw("EXTRACT(HOUR FROM posted_at AT TIME ZONE '{$f->timezone}')::int AS hour, COUNT(*) AS transactions, SUM(subtotal - discount_total) AS sales")->get()
            ->map(fn ($r) => ['hour' => (int) $r->hour, 'transactions' => (int) $r->transactions, 'sales' => $financials ? Decimal::money($r->sales) : null])->all();

        $data['top_products'] = DB::table('sales_invoice_lines as l')->join('sales_invoices as i', 'i.id', '=', 'l.sales_invoice_id')->join('product_variants as v', 'v.id', '=', 'l.variant_id')->join('products as p', 'p.id', '=', 'v.product_id')
            ->where('i.status', 'posted')->whereBetween('i.business_date', [$f->from, $f->to])->whereIn('i.store_id', $f->storeIds)
            ->groupBy('v.id', 'p.name', 'v.name', 'p.name_ur', 'v.name_ur')->orderByDesc(DB::raw('SUM(l.revenue_amount)'))->limit(8)
            ->selectRaw("p.name || CASE WHEN v.name <> p.name THEN ' – ' || v.name ELSE '' END AS name, COALESCE(v.name_ur, p.name_ur) AS name_ur, SUM(l.base_quantity) AS units, SUM(l.line_total) AS sales")->get()
            ->map(fn ($r) => ['name' => $r->name, 'name_ur' => $r->name_ur, 'units' => Decimal::trim($r->units), 'sales' => $financials ? Decimal::money($r->sales) : null])->all();

        $data['payment_methods'] = $financials ? DB::table('payments as p')->join('payment_methods as m', 'm.id', '=', 'p.payment_method_id')
            ->whereIn('p.kind', ['sale', 'collection', 'delivery_collection', 'refund'])->where('p.verification_status', '!=', 'rejected')
            ->whereBetween('p.business_date', [$f->from, $f->to])->whereIn('p.store_id', $f->storeIds)->groupBy('m.id', 'm.name', 'm.name_ur', 'm.type')
            ->selectRaw("m.name, m.name_ur, m.type, SUM(CASE WHEN p.direction = 'in' THEN p.amount ELSE -p.amount END) AS amount, SUM(CASE WHEN p.verification_status = 'pending' THEN p.amount ELSE 0 END) AS pending")
            ->orderByDesc('amount')->get()->map(fn ($r) => ['name' => $r->name, 'name_ur' => $r->name_ur, 'type' => $r->type, 'amount' => Decimal::money($r->amount), 'pending' => Decimal::money($r->pending)])->all() : [];
        if ($financials && $now['khata_extended'] !== '0.00') {
            $data['payment_methods'][] = ['name' => 'Khata (credit given)', 'name_ur' => 'کھاتہ', 'type' => 'credit', 'amount' => $now['khata_extended'], 'pending' => '0.00'];
        }

        $data['shifts'] = DB::table('shifts as s')->join('registers as r', 'r.id', '=', 's.register_id')->join('users as u', 'u.id', '=', 's.opened_by')
            ->whereIn('s.store_id', $f->storeIds)->where(fn ($q) => $q->whereBetween('s.business_date', [$f->from, $f->to])->orWhere('s.status', 'open'))
            ->orderByDesc('s.opened_at')->limit(12)->get(['s.id', 's.number', 's.status', 'r.name as register', 'u.name as cashier', 's.opened_at', 's.closed_at', 's.variance', 's.variance_status'])
            ->map(fn ($s) => ['id' => $s->id, 'number' => $s->number, 'status' => $s->status, 'register' => $s->register, 'cashier' => $s->cashier, 'opened_at' => $s->opened_at, 'closed_at' => $s->closed_at,
                'variance' => $financials && $s->variance !== null ? Decimal::money($s->variance) : null, 'variance_status' => $s->variance_status,
                'ok' => $s->status === 'closed' && ($s->variance === null || Decimal::isZero($s->variance) || in_array($s->variance_status, ['none', 'approved'], true))])->all();

        $counts = DB::table('stock_counts as c')->whereIn('c.store_id', $f->storeIds)->orderByDesc('c.id')->limit(5)->get(['c.id', 'c.number', 'c.status', 'c.snapshot_at', 'c.posted_at']);
        $data['counts'] = $counts->map(function ($c) use ($financials) {
            $r = DB::table('stock_count_lines')->where('stock_count_id', $c->id)->selectRaw('COUNT(*) AS n, SUM(CASE WHEN counted_quantity IS NOT NULL THEN 1 ELSE 0 END) AS counted, SUM(CASE WHEN COALESCE(variance_quantity, 0) = 0 THEN 1 ELSE 0 END) AS exact, COALESCE(SUM(variance_value), 0) AS value')->first();

            return ['id' => $c->id, 'number' => $c->number, 'status' => $c->status, 'snapshot_at' => $c->snapshot_at, 'lines' => (int) $r->n, 'counted' => (int) $r->counted,
                'matched' => (int) $r->exact, 'accuracy' => $r->n > 0 ? round(100 * $r->exact / $r->n, 1) : null, 'variance_value' => $financials ? Decimal::money($r->value) : null];
        })->all();

        $data['alerts'] = $this->alerts($user, $f, $financials);

        return $data;
    }

    /** Things that need someone's attention now. @return list<array{key: string, count: int, href: string, tone: string}> */
    private function alerts(User $user, ReportFilters $f, bool $financials): array
    {
        $alerts = [];
        $add = function (string $key, int $count, string $href, string $tone = 'warning', ?string $permission = null) use (&$alerts, $user) {
            if ($count > 0 && ($permission === null || $user->hasPermission($permission))) {
                $alerts[] = ['key' => $key, 'count' => $count, 'href' => $href, 'tone' => $tone];
            }
        };
        $add('open_shifts_old', DB::table('shifts')->whereIn('store_id', $f->storeIds)->where('status', 'open')->where('opened_at', '<', now()->subHours(16))->count(), '/pos/shifts', 'danger', 'pos.shift.view_all');
        $add('variance_review', DB::table('shifts')->whereIn('store_id', $f->storeIds)->where('variance_status', 'pending_review')->count(), '/pos/shifts', 'warning', 'pos.shift.approve_variance');
        $add('payments_to_verify', DB::table('payments')->whereIn('store_id', $f->storeIds)->where('verification_status', 'pending')->count(), '/sales/payments/verify', 'warning', 'sales.payments.verify');
        $add('adjustments_to_approve', DB::table('stock_adjustments')->whereIn('store_id', $f->storeIds)->whereIn('status', ['pending_approval', 'pending_second_approval'])->count(), '/inventory/adjustments', 'warning', 'inventory.adjust.approve');
        $add('counts_to_review', DB::table('stock_counts')->whereIn('store_id', $f->storeIds)->where('status', 'submitted')->count(), '/inventory/counts', 'warning', 'inventory.counts.approve');
        $add('low_stock', DB::table('replenishment_policies as rp')->whereIn('rp.store_id', $f->storeIds)->where('rp.is_active', true)
            ->whereRaw('(SELECT COALESCE(SUM(sl.on_hand),0) FROM stock_levels sl JOIN locations l ON l.id = sl.location_id WHERE sl.store_id = rp.store_id AND sl.variant_id = rp.variant_id AND l.is_sellable) <= rp.reorder_point')->count(), '/reports/inventory.low_stock', 'warning', 'reports.inventory');
        $add('near_expiry', DB::table('stock_levels as sl')->join('stock_batches as b', 'b.id', '=', 'sl.batch_id')->whereIn('sl.store_id', $f->storeIds)->where('sl.on_hand', '>', 0)
            ->where('b.expiry_date', '<=', now()->addDays(30)->toDateString())->count(), '/reports/inventory.expiry', 'warning', 'reports.inventory');
        $add('pos_to_approve', DB::table('purchase_orders')->whereIn('store_id', $f->storeIds)->where('status', 'submitted')->count(), '/purchasing/orders', 'info', 'purchasing.orders.approve');
        $add('leave_to_review', DB::table('leave_requests')->where('status', 'pending')->count(), '/hr/leave', 'info', 'hr.leave.approve');
        $add('orders_to_confirm', DB::table('sales_orders')->whereIn('store_id', $f->storeIds)->where('status', 'draft')->count(), '/sales/orders', 'info', 'sales.orders.manage');
        $add('cash_with_riders', DB::table('payments')->whereIn('store_id', $f->storeIds)->where('custody', 'rider')->whereNull('rider_handover_id')->count(), '/sales/riders', 'warning', 'sales.deliveries.manage');
        $add('overdue_khata', DB::table('sales_invoices')->whereIn('store_id', $f->storeIds)->where('status', 'posted')->whereIn('payment_status', ['unpaid', 'partial'])->where('due_date', '<', now()->toDateString())->distinct()->count('customer_id'), '/reports/customers.aging', 'warning', 'crm.khata.view');

        return $alerts;
    }
}
