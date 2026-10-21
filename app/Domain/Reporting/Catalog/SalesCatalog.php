<?php

namespace App\Domain\Reporting\Catalog;

use App\Domain\Reporting\DefinedReport;
use App\Domain\Reporting\ReportFilters as F;
use App\Domain\Reporting\SalesMetrics;
use App\Support\Decimal;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;

/** Executive, sales, product, customer, payment, agent and POS reports. */
final class SalesCatalog
{
    public static function card(string $key, string $label, mixed $value, string $type, mixed $previous = null, ?string $note = null): array
    {
        return ['key' => $key, 'label' => $label, 'value' => $value, 'type' => $type, 'previous' => $previous, 'change' => $previous !== null && $type !== 'text' ? SalesMetrics::change($value, $previous) : null, 'note' => $note];
    }

    /** @return list<array<string, mixed>> */
    public static function headline(F $f, bool $withMargin = true): array
    {
        $now = SalesMetrics::totals($f);
        $prev = $f->comparison() ? SalesMetrics::totals($f->comparison()) : [];
        $cards = [
            self::card('net_sales', 'Net sales', $now['net_sales'], 'money', $prev['net_sales'] ?? null),
            self::card('transactions', 'Transactions', $now['transactions'], 'int', $prev['transactions'] ?? null),
            self::card('average_basket', 'Average basket', $now['average_basket'], 'money', $prev['average_basket'] ?? null),
            self::card('discounts', 'Discounts', $now['discounts'], 'money', $prev['discounts'] ?? null, $now['discount_rate'] !== null ? $now['discount_rate'].'% of gross' : null),
            self::card('returns', 'Returns', $now['returns'], 'money', $prev['returns'] ?? null, $now['returns_count'].' credit notes'),
            self::card('collected', 'Money collected', $now['collected'], 'money', $prev['collected'] ?? null),
        ];
        if ($withMargin) {
            $cards[] = self::card('gross_margin', 'Gross margin', $now['gross_margin'], 'money', $prev['gross_margin'] ?? null,
                ($now['gross_margin_percent'] !== null ? $now['gross_margin_percent'].'% of net revenue' : '').($now['cost_complete'] ? '' : ' · some costs unknown, margin overstated'));
        }

        return $cards;
    }

    /** @param list<array<string, mixed>> $summary */
    public static function changeInsights(F $f, array $summary): array
    {
        $insights = [];
        foreach ($summary as $card) {
            if (($card['change'] ?? null) === null || in_array($card['key'], ['returns', 'discounts'], true) === false && abs((float) $card['change']) < 10) {
                continue;
            }
            if (abs((float) $card['change']) < 10) {
                continue;
            }
            $direction = (float) $card['change'] > 0 ? 'up' : 'down';
            $insights[] = ['kind' => 'observed', 'text' => "{$card['label']} is {$direction} ".abs((float) $card['change'])."% against {$f->compareFrom} to {$f->compareTo} (Rs/count {$card['previous']} → {$card['value']}).", 'evidence' => 'Same metric definition for both periods.'];
        }
        if ($insights === [] && $f->hasComparison()) {
            $insights[] = ['kind' => 'observed', 'text' => 'No headline figure moved by 10% or more against the comparison period.'];
        }

        return $insights;
    }

    private static function invoices(F $f)
    {
        return DB::table('sales_invoices as i')->where('i.status', 'posted')->whereBetween('i.business_date', [$f->from, $f->to])->whereIn('i.store_id', $f->storeIds);
    }

    private static function lines(F $f)
    {
        return DB::table('sales_invoice_lines as l')->join('sales_invoices as i', 'i.id', '=', 'l.sales_invoice_id')
            ->join('product_variants as v', 'v.id', '=', 'l.variant_id')->join('products as p', 'p.id', '=', 'v.product_id')
            ->where('i.status', 'posted')->whereBetween('i.business_date', [$f->from, $f->to])->whereIn('i.store_id', $f->storeIds)
            ->when($f->entity('category_id'), fn ($q, $id) => $q->where('p.category_id', $id))
            ->when($f->entity('brand_id'), fn ($q, $id) => $q->where('p.brand_id', $id))
            ->when($f->entity('product_id'), fn ($q, $id) => $q->where('p.id', $id))
            ->when($f->entity('customer_id'), fn ($q, $id) => $q->where('i.customer_id', $id))
            ->when($f->entity('cashier_id'), fn ($q, $id) => $q->where('i.cashier_id', $id));
    }

    /** @return list<array<string, mixed>> */
    public static function reports(): array
    {
        $money = fn (string $label, array $extra = []) => ['label' => $label, 'type' => 'money'] + $extra;
        $cost = 'catalog.costs.view';

        return [
            [
                'key' => 'executive.overview', 'section' => 'executive', 'title' => 'Business overview', 'title_ur' => 'کاروبار کا خلاصہ', 'permission' => 'reports.sales',
                'description' => 'How much we sold, earned and collected, by store, against the previous period.', 'comparison' => true,
                'definition' => ['source' => 'Posted invoices, credit notes and payments', 'date_basis' => 'Business date of each document (store time zone)',
                    'definitions' => ['Net sales' => 'Gross sales − discounts − returns. Excludes cash rounding and delivery charges.', 'Gross margin' => 'Net revenue excluding tax − cost of goods sold (returns restocked are added back).', 'Money collected' => 'Cash and other tenders received for sales, Wasooli and deliveries, less refunds. Rejected wallet payments are excluded.'],
                    'limitations' => ['Margin is overstated when a product was sold without a known cost; the card says so when that happens.']],
                'columns' => fn (F $f) => ['store' => ['label' => 'Store', 'type' => 'text'], 'transactions' => ['label' => 'Transactions', 'type' => 'int'], 'gross_sales' => $money('Gross sales'), 'discounts' => $money('Discounts'), 'returns' => $money('Returns'), 'net_sales' => $money('Net sales'), 'gross_margin' => $money('Gross margin', ['sensitive' => $cost]), 'khata_extended' => $money('Put on Khata')],
                'rows' => fn (F $f) => DB::query()->fromSub(DB::table('stores as s')->whereIn('s.id', $f->storeIds)
                    ->selectRaw('s.name as store')
                    ->selectSub(fn ($q) => $q->from('sales_invoices')->whereColumn('store_id', 's.id')->where('status', 'posted')->whereBetween('business_date', [$f->from, $f->to])->selectRaw('COUNT(*)'), 'transactions')
                    ->selectSub(fn ($q) => $q->from('sales_invoices')->whereColumn('store_id', 's.id')->where('status', 'posted')->whereBetween('business_date', [$f->from, $f->to])->selectRaw('COALESCE(SUM(subtotal),0)'), 'gross_sales')
                    ->selectSub(fn ($q) => $q->from('sales_invoices')->whereColumn('store_id', 's.id')->where('status', 'posted')->whereBetween('business_date', [$f->from, $f->to])->selectRaw('COALESCE(SUM(discount_total),0)'), 'discounts')
                    ->selectSub(fn ($q) => $q->from('credit_notes')->whereColumn('store_id', 's.id')->whereBetween('business_date', [$f->from, $f->to])->selectRaw('COALESCE(SUM(total),0)'), 'returns')
                    ->selectSub(fn ($q) => $q->from('sales_invoices')->whereColumn('store_id', 's.id')->where('status', 'posted')->whereBetween('business_date', [$f->from, $f->to])->selectRaw('COALESCE(SUM(subtotal - discount_total),0)'), 'net_before_returns')
                    ->selectSub(fn ($q) => $q->from('sales_invoice_lines as l')->join('sales_invoices as i', 'i.id', '=', 'l.sales_invoice_id')->whereColumn('i.store_id', 's.id')->where('i.status', 'posted')->whereBetween('i.business_date', [$f->from, $f->to])->selectRaw('COALESCE(SUM(l.revenue_amount - COALESCE(l.cost_amount,0)),0)'), 'gross_margin')
                    ->selectSub(fn ($q) => $q->from('sales_invoices')->whereColumn('store_id', 's.id')->where('status', 'posted')->whereBetween('business_date', [$f->from, $f->to])->selectRaw('COALESCE(SUM(credit_extended),0)'), 'khata_extended'), 'x')
                    ->selectRaw('store, transactions, gross_sales, discounts, returns, net_before_returns - returns net_sales, gross_margin, khata_extended'),
                'summary' => fn (F $f) => self::headline($f),
                'insights' => fn (F $f, array $s) => self::changeInsights($f, $s),
                'chart' => fn (F $f) => self::dailyChart($f),
                'sort' => ['store', 'asc'],
            ],
            [
                'key' => 'sales.summary', 'section' => 'sales', 'title' => 'Sales summary', 'title_ur' => 'فروخت کا خلاصہ', 'permission' => 'reports.sales',
                'description' => 'Daily, weekly or monthly sales with discounts, returns, tax and money collected. The first report to check every day.', 'comparison' => true,
                'group_by' => ['day' => 'Day', 'week' => 'Week', 'month' => 'Month', 'quarter' => 'Quarter', 'year' => 'Year'],
                'filters' => ['period', 'stores', 'cashier', 'customer'],
                'definition' => ['source' => 'Posted invoices and credit notes', 'date_basis' => 'Business date', 'definitions' => ['Net sales' => 'Gross − discounts − returns (returns counted on the day they were processed).', 'Average basket' => '(Gross − discounts) ÷ transactions.'], 'exclusions' => ['Draft invoices and held carts are never counted.']],
                'columns' => ['period' => ['label' => 'Period', 'type' => 'text'], 'transactions' => ['label' => 'Transactions', 'type' => 'int'], 'gross_sales' => $money('Gross sales'), 'discounts' => $money('Discounts'), 'returns' => $money('Returns'), 'net_sales' => $money('Net sales'), 'tax' => $money('Tax'), 'average_basket' => $money('Average basket'), 'khata_extended' => $money('Put on Khata')],
                'rows' => function (F $f, DefinedReport $r) {
                    $bucket = $r->bucketSql('d.day', $f->groupBy ?? 'day');
                    $sales = DB::table('sales_invoices')->where('status', 'posted')->whereBetween('business_date', [$f->from, $f->to])->whereIn('store_id', $f->storeIds)
                        ->when($f->entity('cashier_id'), fn ($q, $id) => $q->where('cashier_id', $id))->when($f->entity('customer_id'), fn ($q, $id) => $q->where('customer_id', $id))
                        ->selectRaw('business_date as day, COUNT(*) n, SUM(subtotal) gross, SUM(discount_total) disc, SUM(tax_total) tax, SUM(credit_extended) khata, 0 ret, 0 ret_tax')->groupBy('business_date');
                    $returns = DB::table('credit_notes')->whereBetween('business_date', [$f->from, $f->to])->whereIn('store_id', $f->storeIds)
                        ->when($f->entity('customer_id'), fn ($q, $id) => $q->where('customer_id', $id))
                        ->selectRaw('business_date as day, 0 n, 0 gross, 0 disc, 0 tax, 0 khata, SUM(total) ret, SUM(tax_total) ret_tax')->groupBy('business_date');

                    return DB::query()->fromSub($sales->unionAll($returns), 'd')->groupByRaw($bucket)
                        ->selectRaw("{$bucket} as period, SUM(n)::int transactions, SUM(gross) gross_sales, SUM(disc) discounts, SUM(ret) returns, SUM(gross - disc - ret) net_sales, SUM(tax - ret_tax) tax,
                            CASE WHEN SUM(n) = 0 THEN 0 ELSE ROUND(SUM(gross - disc) / SUM(n), 2) END average_basket, SUM(khata) khata_extended");
                },
                'summary' => fn (F $f) => self::headline($f, false),
                'insights' => fn (F $f, array $s) => self::changeInsights($f, $s),
                'chart' => fn (F $f) => self::dailyChart($f),
                'sort' => ['period', 'asc'],
            ],
            [
                'key' => 'sales.by_hour', 'section' => 'sales', 'title' => 'Sales by hour and weekday', 'permission' => 'reports.sales',
                'description' => 'When customers buy: transactions and sales for each hour of the day and day of the week. Use it for staffing and shift planning.',
                'definition' => ['source' => 'Posted invoices', 'date_basis' => 'Time the sale was posted, in each store\'s own time zone'],
                'columns' => ['hour' => ['label' => 'Hour', 'type' => 'text'], 'mon' => $money('Mon'), 'tue' => $money('Tue'), 'wed' => $money('Wed'), 'thu' => $money('Thu'), 'fri' => $money('Fri'), 'sat' => $money('Sat'), 'sun' => $money('Sun'), 'transactions' => ['label' => 'Transactions', 'type' => 'int'], 'total' => $money('Total')],
                'rows' => function (F $f) {
                    $local = $f->localTimeSql('i.posted_at', 'i.store_id');
                    $day = fn (int $dow) => "SUM(CASE WHEN EXTRACT(ISODOW FROM {$local}) = {$dow} THEN i.subtotal - i.discount_total ELSE 0 END)";

                    return self::invoices($f)->groupByRaw("EXTRACT(HOUR FROM {$local})")
                        ->selectRaw("lpad(EXTRACT(HOUR FROM {$local})::int::text, 2, '0') || ':00' as hour, {$day(1)} mon, {$day(2)} tue, {$day(3)} wed, {$day(4)} thu, {$day(5)} fri, {$day(6)} sat, {$day(7)} sun, COUNT(*) transactions, SUM(i.subtotal - i.discount_total) total");
                },
                'sort' => ['hour', 'asc'],
            ],
            [
                'key' => 'sales.by_cashier', 'section' => 'sales', 'title' => 'Sales by cashier', 'title_ur' => 'کیشیئر کے حساب سے فروخت', 'permission' => 'reports.sales',
                'description' => 'Sales, discounts and returns handled by each cashier. Read with shift hours and roles in mind; it is not a ranking of people.',
                'definition' => ['source' => 'Posted invoices and credit notes by the user who posted them', 'date_basis' => 'Business date', 'limitations' => ['A cashier working quieter hours will naturally sell less.']],
                'columns' => ['cashier' => ['label' => 'Cashier', 'type' => 'text'], 'transactions' => ['label' => 'Transactions', 'type' => 'int'], 'net_sales' => $money('Sales after discount'), 'discounts' => $money('Discounts given'), 'discount_rate' => ['label' => 'Discount %', 'type' => 'percent'], 'returns' => $money('Returns processed'), 'average_basket' => $money('Average basket')],
                'rows' => fn (F $f, DefinedReport $r) => self::invoices($f)->join('users as u', 'u.id', '=', 'i.cashier_id')->groupBy('u.id', 'u.name')
                    ->selectRaw('u.name cashier, COUNT(*) transactions, SUM(i.subtotal - i.discount_total) net_sales, SUM(i.discount_total) discounts, '.$r->pctSql('SUM(i.discount_total)', 'SUM(i.subtotal)').' discount_rate, ROUND(AVG(i.subtotal - i.discount_total), 2) average_basket')
                    ->selectSub(fn ($q) => $q->from('credit_notes')->whereColumn('posted_by', 'u.id')->whereBetween('business_date', [$f->from, $f->to])->whereIn('store_id', $f->storeIds)->selectRaw('COALESCE(SUM(total),0)'), 'returns'),
                'sort' => ['net_sales', 'desc'],
            ],
            [
                'key' => 'sales.discounts', 'section' => 'sales', 'title' => 'Discounts', 'permission' => 'reports.sales',
                'description' => 'Every discount by reason, cashier and product, with the discount rate. A sales rise is not a success if it was bought with discounts.',
                'filters' => ['period', 'stores', 'cashier', 'category'],
                'definition' => ['source' => 'Invoice lines with a line or bill discount', 'date_basis' => 'Business date', 'definitions' => ['Discount rate' => 'Discount ÷ gross amount of the same lines.']],
                'columns' => ['reason' => ['label' => 'Reason', 'type' => 'text'], 'cashier' => ['label' => 'Cashier', 'type' => 'text'], 'product' => ['label' => 'Product', 'type' => 'text'], 'lines' => ['label' => 'Lines', 'type' => 'int'], 'gross' => $money('Gross'), 'discount' => $money('Discount'), 'discount_rate' => ['label' => 'Discount %', 'type' => 'percent'], 'approved' => ['label' => 'Manager approved', 'type' => 'int']],
                'rows' => fn (F $f, DefinedReport $r) => self::lines($f)->leftJoin('reason_codes as rc', 'rc.id', '=', 'l.discount_reason_id')->leftJoin('users as u', 'u.id', '=', 'i.cashier_id')
                    ->whereRaw('(l.line_discount + l.cart_discount_share) > 0')->groupBy('rc.label', 'u.name', 'p.name', 'v.name')
                    ->selectRaw("COALESCE(rc.label, 'No reason given') reason, COALESCE(u.name, '-') cashier, p.name || ' – ' || v.name product, COUNT(*) lines, SUM(l.list_price * l.quantity) gross, SUM(l.line_discount + l.cart_discount_share) discount, ".$r->pctSql('SUM(l.line_discount + l.cart_discount_share)', 'SUM(l.list_price * l.quantity)').' discount_rate, COUNT(l.approval_id) approved'),
                'sort' => ['discount', 'desc'],
            ],
            [
                'key' => 'sales.returns', 'section' => 'sales', 'title' => 'Returns and refunds', 'title_ur' => 'واپسی', 'permission' => 'reports.sales',
                'description' => 'Credit notes by reason and product, how the money went back (refund or Khata) and what happened to the goods.',
                'definition' => ['source' => 'Credit notes and their lines', 'date_basis' => 'Date the return was processed', 'limitations' => ['Returns are reviewed as exceptions, not proof of wrongdoing.']],
                'columns' => ['number' => ['label' => 'Credit note', 'type' => 'text'], 'date' => ['label' => 'Date', 'type' => 'date'], 'original' => ['label' => 'Original receipt', 'type' => 'text'], 'customer' => ['label' => 'Customer', 'type' => 'text'], 'reason' => ['label' => 'Reason', 'type' => 'text'], 'settlement' => ['label' => 'Paid back as', 'type' => 'text'], 'total' => $money('Amount'), 'processed_by' => ['label' => 'Processed by', 'type' => 'text'], 'approved' => ['label' => 'Approved by manager', 'type' => 'text'], 'invoice_id' => ['label' => 'id', 'type' => 'hidden']],
                'rows' => fn (F $f) => DB::table('credit_notes as c')->join('sales_invoices as i', 'i.id', '=', 'c.sales_invoice_id')->leftJoin('reason_codes as rc', 'rc.id', '=', 'c.reason_code_id')
                    ->leftJoin('users as u', 'u.id', '=', 'c.posted_by')->leftJoin('approvals as a', 'a.id', '=', 'c.approval_id')->leftJoin('users as au', 'au.id', '=', 'a.approved_by')
                    ->whereBetween('c.business_date', [$f->from, $f->to])->whereIn('c.store_id', $f->storeIds)
                    ->selectRaw("c.number, c.business_date date, i.number original, COALESCE(i.customer_name, 'Walk-in') customer, rc.label reason, CASE c.settlement WHEN 'refund' THEN 'Refund' ELSE 'Khata credit' END settlement, c.total, u.name processed_by, au.name approved, i.id invoice_id"),
                'summary' => function (F $f) {
                    $t = SalesMetrics::totals($f);

                    return [self::card('returns', 'Returns', $t['returns'], 'money'), self::card('count', 'Credit notes', $t['returns_count'], 'int'), self::card('rate', 'Return rate', $t['return_rate'], 'percent')];
                },
                'drill' => ['column' => 'original', 'route' => 'sales.invoices.show', 'param' => 'invoice_id'],
                'sort' => ['date', 'desc'],
            ],
            [
                'key' => 'sales.channels', 'section' => 'sales', 'title' => 'Retail vs wholesale and channels', 'permission' => 'reports.sales',
                'description' => 'Sales split by customer type (walk-in, retail, wholesale), price book and order channel.',
                'definition' => ['source' => 'Posted invoices', 'date_basis' => 'Business date', 'definitions' => ['Walk-in' => 'Sales without a named customer.']],
                'columns' => ['channel' => ['label' => 'Channel', 'type' => 'text'], 'customer_type' => ['label' => 'Customer type', 'type' => 'text'], 'transactions' => ['label' => 'Transactions', 'type' => 'int'], 'net_sales' => $money('Sales after discount'), 'share' => ['label' => 'Share %', 'type' => 'percent']],
                'rows' => fn (F $f) => self::invoices($f)->leftJoin('customer_profiles as cp', 'cp.contact_id', '=', 'i.customer_id')->groupBy('i.channel', 'cp.category', DB::raw('i.customer_id IS NULL'))
                    ->selectRaw("i.channel, CASE WHEN i.customer_id IS NULL THEN 'Walk-in' ELSE initcap(COALESCE(cp.category, 'retail')) END customer_type, COUNT(*) transactions, SUM(i.subtotal - i.discount_total) net_sales, ROUND(100.0 * SUM(i.subtotal - i.discount_total) / NULLIF(SUM(SUM(i.subtotal - i.discount_total)) OVER (), 0), 2) share"),
                'sort' => ['net_sales', 'desc'],
            ],
            [
                'key' => 'products.sales', 'section' => 'products', 'title' => 'Product sales', 'title_ur' => 'مصنوعات کی فروخت', 'permission' => 'reports.products',
                'description' => 'Which products sell, how many, for how much and at what margin. Look at both units and value: cheap items can sell in high numbers.',
                'filters' => ['period', 'stores', 'category', 'brand', 'search'],
                'definition' => ['source' => 'Invoice lines less credit-note lines', 'date_basis' => 'Business date of the sale', 'definitions' => ['Units' => 'Quantity in the product\'s base unit (pieces, kg ...).', 'Margin' => 'Revenue ex tax − cost of goods sold.'], 'limitations' => ['Margin is blank where the cost was unknown at the time of sale.']],
                'columns' => ['sku' => ['label' => 'SKU', 'type' => 'text'], 'product' => ['label' => 'Product', 'type' => 'text'], 'category' => ['label' => 'Category', 'type' => 'text'], 'units' => ['label' => 'Units sold', 'type' => 'qty'], 'returned_units' => ['label' => 'Units returned', 'type' => 'qty'], 'gross' => $money('Gross'), 'discounts' => $money('Discounts'), 'revenue' => $money('Revenue ex tax'), 'cost' => $money('Cost', ['sensitive' => $cost]), 'margin' => $money('Margin', ['sensitive' => $cost]), 'margin_percent' => ['label' => 'Margin %', 'type' => 'percent', 'sensitive' => $cost], 'transactions' => ['label' => 'Receipts', 'type' => 'int']],
                'rows' => fn (F $f, DefinedReport $r) => self::lines($f)->leftJoin('product_categories as pc', 'pc.id', '=', 'p.category_id')
                    ->when($f->search, fn ($q, $s) => $q->where(fn ($w) => $w->where('p.name', 'ilike', "%{$s}%")->orWhere('v.sku', 'ilike', "%{$s}%")))
                    ->groupBy('v.id', 'v.sku', 'p.name', 'v.name', 'pc.name')
                    ->selectRaw("v.sku, p.name || CASE WHEN v.name <> p.name THEN ' – ' || v.name ELSE '' END product, COALESCE(pc.name, '-') category, SUM(l.base_quantity) units, SUM(l.base_quantity_returned) returned_units,
                        SUM(l.list_price * l.quantity) gross, SUM(l.line_discount + l.cart_discount_share) discounts, SUM(l.revenue_amount) revenue,
                        CASE WHEN BOOL_AND(l.cost_amount IS NOT NULL) THEN SUM(l.cost_amount) END cost,
                        CASE WHEN BOOL_AND(l.cost_amount IS NOT NULL) THEN SUM(l.revenue_amount - l.cost_amount) END margin,
                        CASE WHEN BOOL_AND(l.cost_amount IS NOT NULL) THEN ".$r->pctSql('SUM(l.revenue_amount - l.cost_amount)', 'SUM(l.revenue_amount)').' END margin_percent, COUNT(DISTINCT i.id) transactions'),
                'sort' => ['revenue', 'desc'],
            ],
            [
                'key' => 'products.by_category', 'section' => 'products', 'title' => 'Sales by category and brand', 'title_ur' => 'زمرہ وار فروخت', 'permission' => 'reports.products',
                'description' => 'Which parts of the business drive sales: categories and brands with their share of revenue and margin.',
                'group_by' => ['category' => 'Category', 'brand' => 'Brand'],
                'definition' => ['source' => 'Invoice lines', 'date_basis' => 'Business date', 'definitions' => ['Share' => 'Revenue of the group ÷ total revenue of the period.']],
                'columns' => ['group_name' => ['label' => 'Category / brand', 'type' => 'text'], 'products' => ['label' => 'Products sold', 'type' => 'int'], 'units' => ['label' => 'Units', 'type' => 'qty'], 'revenue' => $money('Revenue ex tax'), 'share' => ['label' => 'Share %', 'type' => 'percent'], 'margin' => $money('Margin', ['sensitive' => $cost]), 'margin_percent' => ['label' => 'Margin %', 'type' => 'percent', 'sensitive' => $cost]],
                'rows' => function (F $f, DefinedReport $r) {
                    $byBrand = $f->groupBy === 'brand';
                    $q = self::lines($f);
                    $byBrand ? $q->leftJoin('brands as g', 'g.id', '=', 'p.brand_id') : $q->leftJoin('product_categories as g', 'g.id', '=', 'p.category_id');

                    return $q->groupBy('g.name')->selectRaw("COALESCE(g.name, 'Not set') group_name, COUNT(DISTINCT v.id) products, SUM(l.base_quantity) units, SUM(l.revenue_amount) revenue,
                        ROUND(100.0 * SUM(l.revenue_amount) / NULLIF(SUM(SUM(l.revenue_amount)) OVER (), 0), 2) share, SUM(l.revenue_amount - COALESCE(l.cost_amount, 0)) margin, ".$r->pctSql('SUM(l.revenue_amount - COALESCE(l.cost_amount,0))', 'SUM(l.revenue_amount)').' margin_percent');
                },
                'chart' => function (F $f, DefinedReport $r) {
                    $rows = DB::query()->fromSub($r->rows($f), 'x')->orderByDesc('revenue')->limit(10)->get();

                    return ['type' => 'bar', 'labels' => $rows->pluck('group_name')->all(), 'series' => [['name' => 'Revenue', 'data' => $rows->pluck('revenue')->map(fn ($v) => (float) $v)->all()]]];
                },
                'sort' => ['revenue', 'desc'],
            ],
            [
                'key' => 'products.abc', 'section' => 'products', 'title' => 'ABC analysis', 'permission' => 'reports.products',
                'description' => 'Products ranked by revenue: A items make the first 80% of revenue, B the next 15%, C the last 5%. Keep A items always in stock.',
                'definition' => ['source' => 'Invoice lines', 'date_basis' => 'Business date', 'definitions' => ['Class A' => 'Cumulative revenue share up to 80%.', 'Class B' => '80% to 95%.', 'Class C' => 'Above 95%.'], 'limitations' => ['Use at least 90 days for stable classes; products without sales in the period do not appear (see Slow-moving stock).']],
                'columns' => ['rank' => ['label' => '#', 'type' => 'int'], 'sku' => ['label' => 'SKU', 'type' => 'text'], 'product' => ['label' => 'Product', 'type' => 'text'], 'revenue' => $money('Revenue ex tax'), 'share' => ['label' => 'Share %', 'type' => 'percent'], 'cumulative' => ['label' => 'Cumulative %', 'type' => 'percent'], 'class' => ['label' => 'Class', 'type' => 'text']],
                'rows' => fn (F $f) => DB::query()->fromSub(self::lines($f)->groupBy('v.id', 'v.sku', 'p.name', 'v.name')->selectRaw("v.sku, p.name || ' – ' || v.name product, SUM(l.revenue_amount) revenue"), 'x')
                    ->selectRaw("ROW_NUMBER() OVER (ORDER BY revenue DESC, sku) rank, sku, product, revenue, ROUND(100.0 * revenue / NULLIF(SUM(revenue) OVER (), 0), 2) share,
                        ROUND(100.0 * SUM(revenue) OVER (ORDER BY revenue DESC, sku) / NULLIF(SUM(revenue) OVER (), 0), 2) cumulative,
                        CASE WHEN 100.0 * (SUM(revenue) OVER (ORDER BY revenue DESC, sku) - revenue) / NULLIF(SUM(revenue) OVER (), 0) < 80 THEN 'A' WHEN 100.0 * (SUM(revenue) OVER (ORDER BY revenue DESC, sku) - revenue) / NULLIF(SUM(revenue) OVER (), 0) < 95 THEN 'B' ELSE 'C' END class"),
                'sort' => ['rank', 'asc'],
            ],
            [
                'key' => 'products.margin_exceptions', 'section' => 'products', 'title' => 'Low and negative margin sales', 'permission' => 'reports.products',
                'description' => 'Sale lines that earned less than 5% over cost or lost money, with who sold them and whether a manager approved the price.',
                'definition' => ['source' => 'Invoice lines with a known cost', 'date_basis' => 'Business date', 'definitions' => ['Margin %' => '(revenue ex tax − cost) ÷ revenue ex tax.']],
                'columns' => ['receipt' => ['label' => 'Receipt', 'type' => 'text'], 'date' => ['label' => 'Date', 'type' => 'date'], 'product' => ['label' => 'Product', 'type' => 'text'], 'quantity' => ['label' => 'Qty', 'type' => 'qty'], 'unit_price' => $money('Price'), 'revenue' => $money('Revenue ex tax'), 'cost' => $money('Cost', ['sensitive' => $cost]), 'margin_percent' => ['label' => 'Margin %', 'type' => 'percent', 'sensitive' => $cost], 'cashier' => ['label' => 'Cashier', 'type' => 'text'], 'approved' => ['label' => 'Approved', 'type' => 'text'], 'invoice_id' => ['label' => 'id', 'type' => 'hidden']],
                'rows' => fn (F $f, DefinedReport $r) => self::lines($f)->leftJoin('users as u', 'u.id', '=', 'i.cashier_id')->whereNotNull('l.cost_amount')->where('l.revenue_amount', '>', 0)
                    ->whereRaw('(l.revenue_amount - l.cost_amount) < 0.05 * l.revenue_amount')
                    ->selectRaw("i.number receipt, i.business_date date, p.name || ' – ' || v.name product, l.quantity, l.unit_price, l.revenue_amount revenue, l.cost_amount cost, ".$r->pctSql('l.revenue_amount - l.cost_amount', 'l.revenue_amount')." margin_percent, u.name cashier, CASE WHEN l.approval_id IS NULL THEN 'No' ELSE 'Yes' END approved, i.id invoice_id"),
                'drill' => ['column' => 'receipt', 'route' => 'sales.invoices.show', 'param' => 'invoice_id'],
                'sort' => ['margin_percent', 'asc'],
            ],
            [
                'key' => 'products.price_changes', 'section' => 'products', 'title' => 'Price change history', 'permission' => 'reports.products',
                'description' => 'Every selling price change with who made it, the old and new price, and units sold in the 14 days before and after.',
                'definition' => ['source' => 'Effective-dated price history', 'date_basis' => 'Date the new price took effect', 'limitations' => ['Before/after sales also move with season and stock; this shows correlation, not cause.']],
                'columns' => ['changed_at' => ['label' => 'Changed', 'type' => 'datetime'], 'product' => ['label' => 'Product', 'type' => 'text'], 'price_book' => ['label' => 'Price list', 'type' => 'text'], 'old_price' => $money('Old price'), 'new_price' => $money('New price'), 'change_percent' => ['label' => 'Change %', 'type' => 'percent'], 'units_before' => ['label' => 'Units 14d before', 'type' => 'qty'], 'units_after' => ['label' => 'Units 14d after', 'type' => 'qty'], 'changed_by' => ['label' => 'Changed by', 'type' => 'text']],
                'rows' => fn (F $f, DefinedReport $r) => DB::query()->fromSub(DB::table('prices as pr')->join('product_variants as v', 'v.id', '=', 'pr.variant_id')->join('products as p', 'p.id', '=', 'v.product_id')
                    ->join('price_books as pb', 'pb.id', '=', 'pr.price_book_id')->leftJoin('users as u', 'u.id', '=', 'pr.created_by')
                    ->where('pr.company_id', CurrentCompany::require())
                    ->whereBetween(DB::raw("(pr.effective_from AT TIME ZONE '{$f->timezone}')::date"), [$f->from, $f->to])
                    ->selectRaw("pr.effective_from changed_at, p.name || ' – ' || v.name product, pb.name price_book,
                        (SELECT pp.price FROM prices pp WHERE pp.price_book_id = pr.price_book_id AND pp.variant_unit_id = pr.variant_unit_id AND pp.min_quantity = pr.min_quantity AND pp.effective_from < pr.effective_from ORDER BY pp.effective_from DESC LIMIT 1) old_price,
                        pr.price new_price,
                        (SELECT COALESCE(SUM(sl.base_quantity),0) FROM sales_invoice_lines sl JOIN sales_invoices si ON si.id = sl.sales_invoice_id WHERE sl.variant_id = pr.variant_id AND si.store_id IN (".implode(',', $f->storeIds).") AND si.posted_at BETWEEN pr.effective_from - interval '14 days' AND pr.effective_from) units_before,
                        (SELECT COALESCE(SUM(sl.base_quantity),0) FROM sales_invoice_lines sl JOIN sales_invoices si ON si.id = sl.sales_invoice_id WHERE sl.variant_id = pr.variant_id AND si.store_id IN (".implode(',', $f->storeIds).") AND si.posted_at BETWEEN pr.effective_from AND pr.effective_from + interval '14 days') units_after,
                        u.name changed_by"), 'x')->selectRaw('changed_at, product, price_book, old_price, new_price, '.$r->pctSql('new_price - old_price', 'old_price').' change_percent, units_before, units_after, changed_by'),
                'sort' => ['changed_at', 'desc'],
            ],
            [
                'key' => 'customers.khata', 'section' => 'customers', 'title' => 'Khata balances', 'title_ur' => 'کھاتہ بیلنس', 'permission' => 'reports.customers',
                'description' => 'What each customer owes, against their limit, with the oldest overdue days and last payment. Balances come from the accounts ledger.',
                'filters' => ['stores', 'search'],
                'definition' => ['source' => 'Accounts-receivable ledger lines per customer', 'date_basis' => 'Balance as of now', 'definitions' => ['Used %' => 'Balance ÷ credit limit.', 'Overdue days' => 'Days since the oldest unpaid invoice was due.']],
                'columns' => ['code' => ['label' => 'Code', 'type' => 'text'], 'customer' => ['label' => 'Customer', 'type' => 'text'], 'phone' => ['label' => 'Phone', 'type' => 'text', 'sensitive' => 'crm.customers.contact_details'], 'balance' => $money('Balance'), 'credit_limit' => $money('Limit'), 'used_percent' => ['label' => 'Used %', 'type' => 'percent'], 'overdue_days' => ['label' => 'Overdue days', 'type' => 'int'], 'last_payment' => ['label' => 'Last payment', 'type' => 'date'], 'status' => ['label' => 'Status', 'type' => 'text'], 'id' => ['label' => 'id', 'type' => 'hidden']],
                'rows' => fn (F $f, DefinedReport $r) => DB::table('contacts as c')->leftJoin('customer_profiles as cp', 'cp.contact_id', '=', 'c.id')
                    ->where('c.company_id', CurrentCompany::require())->where('c.type', 'customer')->whereNull('c.merged_into_id')
                    ->when($f->search, fn ($q, $s) => $q->where(fn ($w) => $w->where('c.name', 'ilike', "%{$s}%")->orWhere('c.phone', 'ilike', "%{$s}%")))
                    ->selectRaw("c.code, c.name customer, c.phone, b.balance, cp.credit_limit, ".$r->pctSql('b.balance', 'cp.credit_limit')." used_percent,
                        (SELECT MAX(CURRENT_DATE - si.due_date) FROM sales_invoices si WHERE si.customer_id = c.id AND si.payment_status IN ('unpaid','partial') AND si.due_date < CURRENT_DATE) overdue_days,
                        (SELECT MAX(pm.business_date) FROM payments pm WHERE pm.contact_id = c.id AND pm.direction = 'in' AND pm.kind = 'collection') last_payment,
                        initcap(COALESCE(cp.credit_status, 'active')) status, c.id")
                    ->joinSub(DB::table('journal_lines')->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')->where('accounts.system_key', 'accounts_receivable')
                        ->where('journal_lines.company_id', CurrentCompany::require())->whereNotNull('journal_lines.contact_id')
                        ->groupBy('journal_lines.contact_id')->havingRaw('SUM(debit - credit) <> 0')->selectRaw('journal_lines.contact_id, SUM(debit - credit) balance'), 'b', 'b.contact_id', '=', 'c.id'),
                'summary' => function (F $f) {
                    $total = DB::table('journal_lines')->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')->where('accounts.system_key', 'accounts_receivable')->where('journal_lines.company_id', CurrentCompany::require())->selectRaw('COALESCE(SUM(debit - credit),0) b')->value('b');

                    return [self::card('total', 'Total Khata outstanding', Decimal::money($total), 'money', null, 'Equals the receivables balance in the accounts')];
                },
                'drill' => ['column' => 'customer', 'route' => 'crm.customers.show', 'param' => 'id'],
                'sort' => ['balance', 'desc'],
            ],
            [
                'key' => 'customers.aging', 'section' => 'customers', 'title' => 'Khata aging', 'title_ur' => 'کھاتہ کی عمر', 'permission' => 'reports.customers',
                'description' => 'Unpaid invoice balances by how long they are overdue: current, 1-30, 31-60, 61-90 and over 90 days.',
                'filters' => ['stores', 'search'],
                'definition' => ['source' => 'Open invoices (total − paid − credited)', 'date_basis' => 'Days past the due date as of today', 'limitations' => ['Unapplied customer credits are not shown here; the Khata balances report shows the net ledger balance.']],
                'columns' => ['customer' => ['label' => 'Customer', 'type' => 'text'], 'current' => $money('Not yet due'), 'd1_30' => $money('1–30 days'), 'd31_60' => $money('31–60 days'), 'd61_90' => $money('61–90 days'), 'over_90' => $money('Over 90 days'), 'total' => $money('Total'), 'id' => ['label' => 'id', 'type' => 'hidden']],
                'rows' => function (F $f) {
                    $bal = '(si.total - si.amount_paid - si.amount_credited)';
                    $age = '(CURRENT_DATE - COALESCE(si.due_date, si.business_date))';

                    return DB::table('sales_invoices as si')->join('contacts as c', 'c.id', '=', 'si.customer_id')->where('si.status', 'posted')->whereIn('si.payment_status', ['unpaid', 'partial'])->whereIn('si.store_id', $f->storeIds)
                        ->when($f->search, fn ($q, $s) => $q->where('c.name', 'ilike', "%{$s}%"))->whereRaw("{$bal} > 0")->groupBy('c.id', 'c.name')
                        ->selectRaw("c.name customer, SUM(CASE WHEN {$age} <= 0 THEN {$bal} ELSE 0 END) current, SUM(CASE WHEN {$age} BETWEEN 1 AND 30 THEN {$bal} ELSE 0 END) d1_30,
                            SUM(CASE WHEN {$age} BETWEEN 31 AND 60 THEN {$bal} ELSE 0 END) d31_60, SUM(CASE WHEN {$age} BETWEEN 61 AND 90 THEN {$bal} ELSE 0 END) d61_90,
                            SUM(CASE WHEN {$age} > 90 THEN {$bal} ELSE 0 END) over_90, SUM({$bal}) total, c.id");
                },
                'drill' => ['column' => 'customer', 'route' => 'crm.customers.show', 'param' => 'id'],
                'sort' => ['total', 'desc'],
            ],
            [
                'key' => 'customers.clv', 'section' => 'customers', 'title' => 'Customer lifetime value (realised)', 'permission' => 'reports.customers',
                'description' => 'What each named customer has actually bought since their first purchase: orders, spend, average order, frequency and recency. This is history, not a prediction.',
                'filters' => ['stores', 'search'],
                'definition' => ['source' => 'All posted invoices and credit notes of named customers', 'date_basis' => 'Whole history up to the end date', 'definitions' => ['Lifetime net sales' => 'Invoiced − returned.', 'Frequency' => 'Orders per month since the first purchase.'], 'limitations' => ['Walk-in sales cannot be attributed to a customer.', 'Predicted future value is not calculated: there is not yet a validated model.']],
                'columns' => ['customer' => ['label' => 'Customer', 'type' => 'text'], 'first_purchase' => ['label' => 'First purchase', 'type' => 'date'], 'last_purchase' => ['label' => 'Last purchase', 'type' => 'date'], 'orders' => ['label' => 'Orders', 'type' => 'int'], 'lifetime_sales' => $money('Lifetime net sales'), 'average_order' => $money('Average order'), 'per_month' => ['label' => 'Orders / month', 'type' => 'decimal'], 'days_since' => ['label' => 'Days since last', 'type' => 'int'], 'margin' => $money('Lifetime margin', ['sensitive' => $cost]), 'id' => ['label' => 'id', 'type' => 'hidden']],
                'rows' => fn (F $f) => DB::table('sales_invoices as si')->join('contacts as c', 'c.id', '=', 'si.customer_id')->where('si.status', 'posted')->where('si.business_date', '<=', $f->to)->whereIn('si.store_id', $f->storeIds)
                    ->when($f->search, fn ($q, $s) => $q->where('c.name', 'ilike', "%{$s}%"))->groupBy('c.id', 'c.name')
                    ->selectRaw("c.name customer, MIN(si.business_date) first_purchase, MAX(si.business_date) last_purchase, COUNT(*) orders, SUM(si.total - si.amount_credited) lifetime_sales,
                        ROUND(SUM(si.total - si.amount_credited) / COUNT(*), 2) average_order, ROUND(COUNT(*) / GREATEST(1, (MAX(si.business_date) - MIN(si.business_date)) / 30.0), 2) per_month,
                        (DATE '{$f->to}' - MAX(si.business_date)) days_since, SUM(si.total - si.tax_total - si.cost_total) margin, c.id"),
                'drill' => ['column' => 'customer', 'route' => 'crm.customers.show', 'param' => 'id'],
                'sort' => ['lifetime_sales', 'desc'],
            ],
            [
                'key' => 'customers.retention', 'section' => 'customers', 'title' => 'New and returning customers', 'permission' => 'reports.customers',
                'description' => 'Each month: customers buying for the first time, returning customers, and their sales.',
                'definition' => ['source' => 'Posted invoices of named customers', 'date_basis' => 'Business date', 'definitions' => ['New' => 'First ever purchase was in that month.', 'Returning' => 'Bought before that month too.'], 'limitations' => ['Walk-in sales are excluded.']],
                'columns' => ['month' => ['label' => 'Month', 'type' => 'text'], 'new_customers' => ['label' => 'New', 'type' => 'int'], 'returning_customers' => ['label' => 'Returning', 'type' => 'int'], 'new_sales' => $money('Sales from new'), 'returning_sales' => $money('Sales from returning')],
                'rows' => fn (F $f) => DB::table('sales_invoices as si')->joinSub(DB::table('sales_invoices')->whereNotNull('customer_id')->where('status', 'posted')->whereIn('store_id', $f->storeIds)->groupBy('customer_id')->selectRaw("customer_id, date_trunc('month', MIN(business_date)) first_month"), 'fm', 'fm.customer_id', '=', 'si.customer_id')
                    ->where('si.status', 'posted')->whereBetween('si.business_date', [$f->from, $f->to])->whereIn('si.store_id', $f->storeIds)->groupByRaw("date_trunc('month', si.business_date)")
                    ->selectRaw("to_char(date_trunc('month', si.business_date), 'YYYY-MM') AS month, COUNT(DISTINCT CASE WHEN fm.first_month = date_trunc('month', si.business_date) THEN si.customer_id END) new_customers,
                        COUNT(DISTINCT CASE WHEN fm.first_month < date_trunc('month', si.business_date) THEN si.customer_id END) returning_customers,
                        SUM(CASE WHEN fm.first_month = date_trunc('month', si.business_date) THEN si.total ELSE 0 END) new_sales, SUM(CASE WHEN fm.first_month < date_trunc('month', si.business_date) THEN si.total ELSE 0 END) returning_sales"),
                'sort' => ['month', 'asc'],
            ],
            [
                'key' => 'payments.by_method', 'section' => 'payments', 'title' => 'Payment methods', 'title_ur' => 'ادائیگی کے طریقے', 'permission' => 'reports.sales',
                'description' => 'How customers pay: cash, card, Easypaisa, JazzCash and others, with refunds and amounts still waiting for verification.',
                'definition' => ['source' => 'Payments received and refunded', 'date_basis' => 'Business date of the payment', 'definitions' => ['Waiting verification' => 'Wallet, card and bank payments not yet matched to a statement.'], 'exclusions' => ['Supplier payments.']],
                'columns' => ['method' => ['label' => 'Method', 'type' => 'text'], 'count' => ['label' => 'Payments', 'type' => 'int'], 'received' => $money('Received'), 'refunded' => $money('Refunded'), 'net' => $money('Net'), 'pending' => $money('Waiting verification'), 'rejected' => $money('Rejected'), 'share' => ['label' => 'Share %', 'type' => 'percent']],
                'rows' => fn (F $f) => DB::table('payments as pm')->join('payment_methods as m', 'm.id', '=', 'pm.payment_method_id')->whereIn('pm.kind', ['sale', 'collection', 'delivery_collection', 'refund'])
                    ->whereBetween('pm.business_date', [$f->from, $f->to])->whereIn('pm.store_id', $f->storeIds)->groupBy('m.id', 'm.name')
                    ->selectRaw("m.name method, COUNT(*) count, SUM(CASE WHEN direction='in' AND verification_status <> 'rejected' THEN amount ELSE 0 END) received, SUM(CASE WHEN direction='out' THEN amount ELSE 0 END) refunded,
                        SUM(CASE WHEN verification_status = 'rejected' THEN 0 WHEN direction='in' THEN amount ELSE -amount END) net, SUM(CASE WHEN verification_status = 'pending' THEN amount ELSE 0 END) pending,
                        SUM(CASE WHEN verification_status = 'rejected' THEN amount ELSE 0 END) rejected,
                        ROUND(100.0 * SUM(CASE WHEN verification_status = 'rejected' THEN 0 WHEN direction='in' THEN amount ELSE -amount END) / NULLIF(SUM(SUM(CASE WHEN verification_status = 'rejected' THEN 0 WHEN direction='in' THEN amount ELSE -amount END)) OVER (), 0), 2) share"),
                'chart' => function (F $f, DefinedReport $r) {
                    $rows = DB::query()->fromSub($r->rows($f), 'x')->get();

                    return ['type' => 'bar', 'labels' => $rows->pluck('method')->all(), 'series' => [['name' => 'Net received', 'data' => $rows->pluck('net')->map(fn ($v) => (float) $v)->all()]]];
                },
                'sort' => ['net', 'desc'],
            ],
            [
                'key' => 'payments.verification', 'section' => 'payments', 'title' => 'Wallet and card payments to verify', 'permission' => 'reports.sales',
                'description' => 'Easypaisa, JazzCash, card and bank payments with their reference, waiting to be matched to the actual statement.',
                'filters' => ['period', 'stores', 'payment_method'],
                'definition' => ['source' => 'Payments of methods that need verification', 'date_basis' => 'Business date', 'limitations' => ['A typed reference number is not proof the money arrived.']],
                'columns' => ['number' => ['label' => 'Payment', 'type' => 'text'], 'date' => ['label' => 'Date', 'type' => 'date'], 'method' => ['label' => 'Method', 'type' => 'text'], 'reference' => ['label' => 'Reference', 'type' => 'text'], 'terminal' => ['label' => 'Terminal', 'type' => 'text'], 'customer' => ['label' => 'Customer', 'type' => 'text'], 'amount' => $money('Amount'), 'status' => ['label' => 'Status', 'type' => 'status'], 'received_by' => ['label' => 'Taken by', 'type' => 'text'], 'id' => ['label' => 'id', 'type' => 'hidden']],
                'rows' => fn (F $f) => DB::table('payments as pm')->join('payment_methods as m', 'm.id', '=', 'pm.payment_method_id')->leftJoin('contacts as c', 'c.id', '=', 'pm.contact_id')->leftJoin('users as u', 'u.id', '=', 'pm.received_by')
                    ->where('m.requires_verification', true)->where('pm.direction', 'in')->whereBetween('pm.business_date', [$f->from, $f->to])->whereIn('pm.store_id', $f->storeIds)
                    ->when($f->entity('payment_method_id'), fn ($q, $id) => $q->where('m.id', $id))
                    ->selectRaw("pm.number, pm.business_date date, m.name method, pm.reference, pm.terminal_label terminal, COALESCE(c.name, 'Walk-in') customer, pm.amount, pm.verification_status status, u.name received_by, pm.id"),
                'sort' => ['date', 'desc'],
            ],
            [
                'key' => 'invoices.register', 'section' => 'payments', 'title' => 'Invoice register', 'permission' => 'reports.sales',
                'description' => 'Every invoice and receipt with what was paid, credited and still due. Invoices issued are not cash collected.',
                'filters' => ['period', 'stores', 'customer', 'search'],
                'definition' => ['source' => 'Posted invoices', 'date_basis' => 'Business date', 'definitions' => ['Balance' => 'Total − paid − credited (returns).']],
                'columns' => ['number' => ['label' => 'Number', 'type' => 'text'], 'date' => ['label' => 'Date', 'type' => 'date'], 'kind' => ['label' => 'Type', 'type' => 'text'], 'customer' => ['label' => 'Customer', 'type' => 'text'], 'total' => $money('Total'), 'paid' => $money('Paid'), 'credited' => $money('Returned'), 'balance' => $money('Balance'), 'due_date' => ['label' => 'Due', 'type' => 'date'], 'status' => ['label' => 'Status', 'type' => 'status'], 'id' => ['label' => 'id', 'type' => 'hidden']],
                'rows' => fn (F $f) => self::invoices($f)->when($f->entity('customer_id'), fn ($q, $id) => $q->where('i.customer_id', $id))->when($f->search, fn ($q, $s) => $q->where(fn ($w) => $w->where('i.number', 'ilike', "%{$s}%")->orWhere('i.customer_name', 'ilike', "%{$s}%")))
                    ->selectRaw("i.number, i.business_date date, CASE i.kind WHEN 'pos' THEN 'Receipt' ELSE 'Invoice' END kind, COALESCE(i.customer_name, 'Walk-in') customer, i.total, i.amount_paid paid, i.amount_credited credited, (i.total - i.amount_paid - i.amount_credited) balance, i.due_date, i.payment_status status, i.id"),
                'drill' => ['column' => 'number', 'route' => 'sales.invoices.show', 'param' => 'id'],
                'sort' => ['date', 'desc'],
            ],
            [
                'key' => 'customers.collections', 'section' => 'payments', 'title' => 'Wasooli (collections)', 'title_ur' => 'وصولی', 'permission' => 'reports.customers',
                'description' => 'Money collected against Khata: by customer, method and who collected it, compared with credit extended in the same period.',
                'definition' => ['source' => 'Payments of kind collection and delivery collection', 'date_basis' => 'Business date', 'definitions' => ['Collection rate' => 'Collected ÷ Khata extended in the period (can exceed 100% when old balances are recovered).']],
                'columns' => ['number' => ['label' => 'Payment', 'type' => 'text'], 'date' => ['label' => 'Date', 'type' => 'date'], 'customer' => ['label' => 'Customer', 'type' => 'text'], 'method' => ['label' => 'Method', 'type' => 'text'], 'amount' => $money('Amount'), 'collected_by' => ['label' => 'Collected by', 'type' => 'text'], 'status' => ['label' => 'Verification', 'type' => 'status']],
                'rows' => fn (F $f) => DB::table('payments as pm')->join('payment_methods as m', 'm.id', '=', 'pm.payment_method_id')->leftJoin('contacts as c', 'c.id', '=', 'pm.contact_id')->leftJoin('users as u', 'u.id', '=', 'pm.received_by')
                    ->whereIn('pm.kind', ['collection', 'delivery_collection'])->whereBetween('pm.business_date', [$f->from, $f->to])->whereIn('pm.store_id', $f->storeIds)
                    ->selectRaw('pm.number, pm.business_date date, c.name customer, m.name method, pm.amount, u.name collected_by, pm.verification_status status'),
                'summary' => function (F $f) {
                    $t = SalesMetrics::totals($f);
                    $collected = DB::table('payments')->whereIn('kind', ['collection', 'delivery_collection'])->where('verification_status', '!=', 'rejected')->whereBetween('business_date', [$f->from, $f->to])->whereIn('store_id', $f->storeIds)->sum('amount');

                    return [self::card('extended', 'Khata extended', $t['khata_extended'], 'money'), self::card('collected', 'Collected', Decimal::money($collected), 'money'),
                        self::card('rate', 'Collection rate', Decimal::isPositive($t['khata_extended']) ? Decimal::round(Decimal::div(Decimal::mul($collected, '100'), $t['khata_extended'], 4), 1) : null, 'percent')];
                },
                'sort' => ['date', 'desc'],
            ],
            [
                'key' => 'agents.performance', 'section' => 'agents', 'title' => 'Sales agent performance', 'title_ur' => 'سیلز ایجنٹ کارکردگی', 'permission' => 'reports.sales',
                'description' => 'For each sales agent: orders booked, invoiced, collected and still outstanding, shown separately, plus new customers, cancellations and returns, against targets.',
                'definition' => ['source' => 'Sales orders, invoices, payments and targets attributed to the agent on the order/invoice', 'date_basis' => 'Business date of each document',
                    'definitions' => ['Booked' => 'Value of orders confirmed in the period.', 'Invoiced' => 'Invoices issued in the period.', 'Collected' => 'Payments received in the period on the agent\'s invoices (any invoice date).', 'Outstanding' => 'Current unpaid balance of the agent\'s invoices.', 'Closed' => 'An order is "closed" when it is invoiced.'],
                    'limitations' => ['An agent is credited with collection only when money is actually received.', 'Shared or reassigned accounts follow the agent recorded on each order.']],
                'columns' => ['agent' => ['label' => 'Agent', 'type' => 'text'], 'orders' => ['label' => 'Orders', 'type' => 'int'], 'booked' => $money('Booked'), 'invoiced' => $money('Invoiced'), 'collected' => $money('Collected'), 'outstanding' => $money('Outstanding'), 'cancelled' => ['label' => 'Cancelled', 'type' => 'int'], 'returns' => $money('Returns'), 'new_customers' => ['label' => 'New customers', 'type' => 'int'], 'target' => $money('Target'), 'achievement' => ['label' => 'Achieved %', 'type' => 'percent']],
                'rows' => fn (F $f, DefinedReport $r) => DB::query()->fromSub(DB::table('contacts as a')->where('a.company_id', CurrentCompany::require())
                    ->whereExists(fn ($q) => $q->from('sales_orders')->whereColumn('sales_agent_id', 'a.id'))
                    ->selectRaw('a.name agent')
                    ->selectSub(fn ($q) => $q->from('sales_orders')->whereColumn('sales_agent_id', 'a.id')->whereBetween('business_date', [$f->from, $f->to])->whereIn('store_id', $f->storeIds)->selectRaw('COUNT(*)'), 'orders')
                    ->selectSub(fn ($q) => $q->from('sales_orders')->whereColumn('sales_agent_id', 'a.id')->whereNotNull('confirmed_at')->where('status', '!=', 'cancelled')->whereBetween('business_date', [$f->from, $f->to])->whereIn('store_id', $f->storeIds)->selectRaw('COALESCE(SUM(total),0)'), 'booked')
                    ->selectSub(fn ($q) => $q->from('sales_invoices')->whereColumn('sales_agent_id', 'a.id')->where('status', 'posted')->whereBetween('business_date', [$f->from, $f->to])->whereIn('store_id', $f->storeIds)->selectRaw('COALESCE(SUM(total),0)'), 'invoiced')
                    ->selectSub(fn ($q) => $q->from('payment_allocations as pa')->join('payments as pm', 'pm.id', '=', 'pa.payment_id')->join('sales_invoices as si', fn ($j) => $j->on('si.id', '=', 'pa.allocatable_id')->where('pa.allocatable_type', (new \App\Models\SalesInvoice)->getMorphClass()))->whereColumn('si.sales_agent_id', 'a.id')->where('pm.direction', 'in')->where('pm.verification_status', '!=', 'rejected')->whereBetween('pm.business_date', [$f->from, $f->to])->selectRaw('COALESCE(SUM(pa.amount),0)'), 'collected')
                    ->selectSub(fn ($q) => $q->from('sales_invoices')->whereColumn('sales_agent_id', 'a.id')->where('status', 'posted')->whereIn('payment_status', ['unpaid', 'partial'])->selectRaw('COALESCE(SUM(total - amount_paid - amount_credited),0)'), 'outstanding')
                    ->selectSub(fn ($q) => $q->from('sales_orders')->whereColumn('sales_agent_id', 'a.id')->where('status', 'cancelled')->whereBetween('business_date', [$f->from, $f->to])->selectRaw('COUNT(*)'), 'cancelled')
                    ->selectSub(fn ($q) => $q->from('credit_notes as cn')->join('sales_invoices as si', 'si.id', '=', 'cn.sales_invoice_id')->whereColumn('si.sales_agent_id', 'a.id')->whereBetween('cn.business_date', [$f->from, $f->to])->selectRaw('COALESCE(SUM(cn.total),0)'), 'returns')
                    ->selectSub(fn ($q) => $q->from('customer_profiles as cp')->join('contacts as cc', 'cc.id', '=', 'cp.contact_id')->whereColumn('cp.sales_agent_id', 'a.id')->whereBetween(DB::raw('cc.created_at::date'), [$f->from, $f->to])->selectRaw('COUNT(*)'), 'new_customers')
                    ->selectSub(fn ($q) => $q->from('sales_targets')->whereColumn('sales_agent_id', 'a.id')->where('metric', 'net_sales')->where('period_start', '<=', $f->to)->where('period_end', '>=', $f->from)->selectRaw('SUM(target_value)'), 'target'), 'x')
                    ->selectRaw('x.*, '.$r->pctSql('invoiced', 'target').' achievement'),
                'sort' => ['invoiced', 'desc'],
            ],
            [
                'key' => 'funnel.orders', 'section' => 'agents', 'title' => 'Order funnel', 'permission' => 'reports.sales',
                'description' => 'Orders (phone, field agent, e-commerce) through each stage: created, confirmed, invoiced, delivered and paid, with conversion and cancellations by reason. POS receipts are not orders and are not counted.',
                'filters' => ['period', 'stores'],
                'definition' => ['source' => 'Sales orders created in the period (cohort) and what happened to them since', 'date_basis' => 'Order creation date', 'definitions' => ['Conversion' => 'Orders reaching the stage ÷ orders created (count). Value conversion uses order totals.'], 'limitations' => ['Small numbers of orders make percentages unstable.']],
                'columns' => ['stage' => ['label' => 'Stage', 'type' => 'text'], 'orders' => ['label' => 'Orders', 'type' => 'int'], 'value' => $money('Value'), 'conversion' => ['label' => 'Of created %', 'type' => 'percent'], 'sort_order' => ['label' => 'n', 'type' => 'hidden']],
                'rows' => function (F $f) {
                    $base = DB::table('sales_orders')->whereBetween('business_date', [$f->from, $f->to])->whereIn('store_id', $f->storeIds);
                    $stage = fn (string $label, int $order, string $condition) => (clone $base)->whereRaw($condition)->selectRaw("'{$label}' stage, COUNT(*) orders, COALESCE(SUM(total),0) value, {$order} sort_order");
                    $union = $stage('1. Created', 1, 'true')
                        ->unionAll($stage('2. Confirmed', 2, 'confirmed_at IS NOT NULL'))
                        ->unionAll($stage('3. Invoiced', 3, "status IN ('dispatched','delivered','completed')"))
                        ->unionAll($stage('4. Delivered / handed over', 4, "status IN ('delivered','completed')"))
                        ->unionAll($stage('5. Fully paid', 5, "id IN (SELECT sales_order_id FROM sales_invoices WHERE payment_status = 'paid' AND sales_order_id IS NOT NULL)"))
                        ->unionAll($stage('Cancelled', 6, "status = 'cancelled'"));

                    return DB::query()->fromSub($union, 'f')->selectRaw('stage, orders, value, ROUND(100.0 * orders / NULLIF(FIRST_VALUE(orders) OVER (ORDER BY sort_order), 0), 1) conversion, sort_order');
                },
                'sort' => ['sort_order', 'asc'],
            ],
            [
                'key' => 'sales.targets', 'section' => 'agents', 'title' => 'Targets vs actual', 'permission' => 'reports.sales',
                'description' => 'Each sales target with the actual figure for its own period and the variance.',
                'filters' => ['stores'],
                'definition' => ['source' => 'Sales targets; actuals from invoices (net sales) or payments (collections)', 'date_basis' => 'Each target\'s own period'],
                'columns' => ['who' => ['label' => 'Agent / store', 'type' => 'text'], 'metric' => ['label' => 'Measure', 'type' => 'text'], 'period' => ['label' => 'Period', 'type' => 'text'], 'target' => $money('Target'), 'actual' => $money('Actual'), 'variance' => $money('Variance'), 'achieved' => ['label' => 'Achieved %', 'type' => 'percent']],
                'rows' => fn (F $f, DefinedReport $r) => DB::query()->fromSub(DB::table('sales_targets as t')->leftJoin('contacts as a', 'a.id', '=', 't.sales_agent_id')->leftJoin('stores as s', 's.id', '=', 't.store_id')
                    ->where('t.company_id', CurrentCompany::require())
                    ->selectRaw("COALESCE(a.name, s.name, 'Whole business') who, t.metric, t.period_start || ' to ' || t.period_end period, t.target_value target,
                        CASE t.metric WHEN 'collections' THEN (SELECT COALESCE(SUM(pm.amount),0) FROM payments pm WHERE pm.company_id = t.company_id AND pm.direction='in' AND pm.kind IN ('collection','delivery_collection','sale') AND pm.business_date BETWEEN t.period_start AND t.period_end AND (t.store_id IS NULL OR pm.store_id = t.store_id))
                        ELSE (SELECT COALESCE(SUM(si.subtotal - si.discount_total - si.amount_credited),0) FROM sales_invoices si WHERE si.company_id = t.company_id AND si.status='posted' AND si.business_date BETWEEN t.period_start AND t.period_end AND (t.store_id IS NULL OR si.store_id = t.store_id) AND (t.sales_agent_id IS NULL OR si.sales_agent_id = t.sales_agent_id)) END actual"), 'x')
                    ->selectRaw('who, metric, period, target, actual, actual - target variance, '.$r->pctSql('actual', 'target').' achieved'),
                'sort' => ['period', 'desc'],
            ],
            [
                'key' => 'pos.shifts', 'section' => 'pos', 'title' => 'Shifts and cash reconciliation', 'title_ur' => 'شفٹ اور کیش', 'permission' => 'reports.pos',
                'description' => 'Every shift with opening float, sales, expected and counted cash and the variance. Did each shift close correctly?',
                'filters' => ['period', 'stores', 'cashier'],
                'definition' => ['source' => 'Shifts and their frozen Z-Report snapshots', 'date_basis' => 'Shift business date', 'definitions' => ['Expected cash' => 'Opening float + cash received + pay-ins − cash refunds − payouts − drops.', 'Variance' => 'Counted − expected. Reviewed by a manager, never deducted automatically.']],
                'columns' => ['number' => ['label' => 'Shift', 'type' => 'text'], 'date' => ['label' => 'Date', 'type' => 'date'], 'register' => ['label' => 'POS counter', 'type' => 'text'], 'cashier' => ['label' => 'Cashier', 'type' => 'text'], 'status' => ['label' => 'Status', 'type' => 'status'], 'opening_float' => $money('Float'), 'sales' => $money('Sales'), 'expected' => $money('Expected cash'), 'counted' => $money('Counted'), 'variance' => $money('Variance'), 'variance_status' => ['label' => 'Review', 'type' => 'status'], 'z_number' => ['label' => 'Z', 'type' => 'int'], 'id' => ['label' => 'id', 'type' => 'hidden']],
                'rows' => fn (F $f) => DB::table('shifts as s')->join('registers as r', 'r.id', '=', 's.register_id')->join('users as u', 'u.id', '=', 's.opened_by')
                    ->whereBetween('s.business_date', [$f->from, $f->to])->whereIn('s.store_id', $f->storeIds)->when($f->entity('cashier_id'), fn ($q, $id) => $q->where('s.opened_by', $id))
                    ->selectRaw("s.number, s.business_date date, r.name register, u.name cashier, s.status, s.opening_float, (SELECT COALESCE(SUM(total),0) FROM sales_invoices WHERE shift_id = s.id) sales, s.expected_cash expected, s.counted_cash counted, s.variance, s.variance_status, s.z_number, s.id"),
                'drill' => ['column' => 'number', 'route' => 'pos.shifts.show', 'param' => 'id'],
                'sort' => ['date', 'desc'],
            ],
            [
                'key' => 'pos.end_of_day', 'section' => 'pos', 'title' => 'End-of-day summary', 'title_ur' => 'دن کا اختتام', 'permission' => 'reports.pos',
                'description' => 'One line per store per day: net sales, cash counted and variance, wallet and card totals, Khata given and recovered, and exceptions.',
                'definition' => ['source' => 'Invoices, credit notes, payments, shifts and cash movements', 'date_basis' => 'Business date'],
                'columns' => ['date' => ['label' => 'Date', 'type' => 'date'], 'store' => ['label' => 'Store', 'type' => 'text'], 'net_sales' => $money('Net sales'), 'cash' => $money('Cash taken'), 'digital' => $money('Card / wallet / bank'), 'khata_given' => $money('Khata given'), 'khata_recovered' => $money('Khata recovered'), 'variance' => $money('Cash variance'), 'payouts' => $money('Payouts'), 'open_shifts' => ['label' => 'Shifts still open', 'type' => 'int']],
                'rows' => fn (F $f) => DB::table('stores as st')->crossJoin(DB::raw("generate_series(DATE '{$f->from}', DATE '{$f->to}', interval '1 day') as d(day)"))->whereIn('st.id', $f->storeIds)
                    ->selectRaw("d.day::date date, st.name store,
                        (SELECT COALESCE(SUM(subtotal - discount_total),0) FROM sales_invoices WHERE store_id = st.id AND business_date = d.day::date AND status='posted') - (SELECT COALESCE(SUM(total),0) FROM credit_notes WHERE store_id = st.id AND business_date = d.day::date) net_sales,
                        (SELECT COALESCE(SUM(CASE WHEN direction='in' THEN amount ELSE -amount END),0) FROM payments p JOIN payment_methods m ON m.id = p.payment_method_id WHERE p.store_id = st.id AND p.business_date = d.day::date AND m.type='cash' AND p.kind IN ('sale','collection','refund')) cash,
                        (SELECT COALESCE(SUM(CASE WHEN direction='in' THEN amount ELSE -amount END),0) FROM payments p JOIN payment_methods m ON m.id = p.payment_method_id WHERE p.store_id = st.id AND p.business_date = d.day::date AND m.type <> 'cash' AND p.kind IN ('sale','collection','refund') AND p.verification_status <> 'rejected') digital,
                        (SELECT COALESCE(SUM(credit_extended),0) FROM sales_invoices WHERE store_id = st.id AND business_date = d.day::date) khata_given,
                        (SELECT COALESCE(SUM(amount),0) FROM payments WHERE store_id = st.id AND business_date = d.day::date AND kind IN ('collection','delivery_collection')) khata_recovered,
                        (SELECT COALESCE(SUM(variance),0) FROM shifts WHERE store_id = st.id AND business_date = d.day::date) variance,
                        (SELECT COALESCE(SUM(amount),0) FROM cash_movements WHERE store_id = st.id AND business_date = d.day::date AND type='payout') payouts,
                        (SELECT COUNT(*) FROM shifts WHERE store_id = st.id AND business_date = d.day::date AND status='open') open_shifts"),
                'sort' => ['date', 'desc'],
            ],
            [
                'key' => 'pos.cash_movements', 'section' => 'pos', 'title' => 'Payouts, drops and no-sale openings', 'permission' => 'reports.pos',
                'description' => 'Every movement of drawer cash that is not a sale, with reason, recipient and approver.',
                'definition' => ['source' => 'Cash movements', 'date_basis' => 'Business date'],
                'columns' => ['number' => ['label' => 'No.', 'type' => 'text'], 'date' => ['label' => 'Date', 'type' => 'date'], 'type' => ['label' => 'Type', 'type' => 'status'], 'amount' => $money('Amount'), 'reason' => ['label' => 'Reason', 'type' => 'text'], 'recipient' => ['label' => 'Recipient', 'type' => 'text'], 'by' => ['label' => 'By', 'type' => 'text'], 'approved_by' => ['label' => 'Approved by', 'type' => 'text'], 'shift' => ['label' => 'Shift', 'type' => 'text']],
                'rows' => fn (F $f) => DB::table('cash_movements as cm')->join('shifts as s', 's.id', '=', 'cm.shift_id')->leftJoin('reason_codes as rc', 'rc.id', '=', 'cm.reason_code_id')->leftJoin('users as u', 'u.id', '=', 'cm.user_id')->leftJoin('users as a', 'a.id', '=', 'cm.approved_by')
                    ->whereBetween('cm.business_date', [$f->from, $f->to])->whereIn('cm.store_id', $f->storeIds)
                    ->selectRaw('cm.number, cm.business_date date, cm.type, cm.amount, rc.label reason, cm.recipient, u.name by, a.name approved_by, s.number shift'),
                'sort' => ['date', 'desc'],
            ],
            [
                'key' => 'deliveries.riders', 'section' => 'pos', 'title' => 'Rider deliveries and cash', 'title_ur' => 'رائیڈر', 'permission' => 'reports.sales',
                'description' => 'Per rider: deliveries completed and failed, cash collected, handed over and still with the rider.',
                'definition' => ['source' => 'Deliveries, delivery collections and rider handovers', 'date_basis' => 'Delivery date'],
                'columns' => ['rider' => ['label' => 'Rider', 'type' => 'text'], 'assigned' => ['label' => 'Assigned', 'type' => 'int'], 'delivered' => ['label' => 'Delivered', 'type' => 'int'], 'failed' => ['label' => 'Failed / returned', 'type' => 'int'], 'collected' => $money('COD collected'), 'handed_over' => $money('Handed over'), 'with_rider' => $money('Still with rider'), 'variance' => $money('Handover variance')],
                'rows' => fn (F $f) => DB::table('contacts as r')->where('r.company_id', CurrentCompany::require())->whereExists(fn ($q) => $q->from('deliveries')->whereColumn('rider_id', 'r.id'))
                    ->selectRaw('r.name rider')
                    ->selectSub(fn ($q) => $q->from('deliveries')->whereColumn('rider_id', 'r.id')->whereIn('store_id', $f->storeIds)->whereBetween(DB::raw('created_at::date'), [$f->from, $f->to])->selectRaw('COUNT(*)'), 'assigned')
                    ->selectSub(fn ($q) => $q->from('deliveries')->whereColumn('rider_id', 'r.id')->whereIn('store_id', $f->storeIds)->where('status', 'delivered')->whereBetween(DB::raw('created_at::date'), [$f->from, $f->to])->selectRaw('COUNT(*)'), 'delivered')
                    ->selectSub(fn ($q) => $q->from('deliveries')->whereColumn('rider_id', 'r.id')->whereIn('store_id', $f->storeIds)->whereIn('status', ['failed', 'returned'])->whereBetween(DB::raw('created_at::date'), [$f->from, $f->to])->selectRaw('COUNT(*)'), 'failed')
                    ->selectSub(fn ($q) => $q->from('payments')->whereColumn('rider_id', 'r.id')->where('kind', 'delivery_collection')->whereBetween('business_date', [$f->from, $f->to])->selectRaw('COALESCE(SUM(amount),0)'), 'collected')
                    ->selectSub(fn ($q) => $q->from('rider_handovers')->whereColumn('rider_id', 'r.id')->whereBetween('business_date', [$f->from, $f->to])->selectRaw('COALESCE(SUM(received_amount),0)'), 'handed_over')
                    ->selectSub(fn ($q) => $q->from('payments')->whereColumn('rider_id', 'r.id')->where('custody', 'rider')->whereNull('rider_handover_id')->selectRaw('COALESCE(SUM(amount),0)'), 'with_rider')
                    ->selectSub(fn ($q) => $q->from('rider_handovers')->whereColumn('rider_id', 'r.id')->whereBetween('business_date', [$f->from, $f->to])->selectRaw('COALESCE(SUM(variance),0)'), 'variance'),
                'sort' => ['collected', 'desc'],
            ],
        ];
    }

    /** Net sales per day for the period, with the comparison period aligned day by day. */
    public static function dailyChart(F $f): array
    {
        $series = function (F $period) {
            return DB::table('sales_invoices')->where('status', 'posted')->whereBetween('business_date', [$period->from, $period->to])->whereIn('store_id', $period->storeIds)
                ->groupBy('business_date')->orderBy('business_date')->selectRaw('business_date, SUM(subtotal - discount_total) v')->pluck('v', 'business_date')->all();
        };
        $current = $series($f);
        $labels = [];
        $values = [];
        $previousValues = [];
        $previous = $f->comparison() ? array_values($series($f->comparison())) : null;
        $prevByIndex = $f->comparison() ? $series($f->comparison()) : [];
        $prevStart = $f->compareFrom ? \Carbon\CarbonImmutable::parse($f->compareFrom) : null;
        for ($d = \Carbon\CarbonImmutable::parse($f->from), $i = 0; $d->lte(\Carbon\CarbonImmutable::parse($f->to)) && $i < 400; $d = $d->addDay(), $i++) {
            $labels[] = $d->format('d M');
            $values[] = (float) ($current[$d->toDateString()] ?? 0);
            if ($prevStart) {
                $previousValues[] = (float) ($prevByIndex[$prevStart->addDays($i)->toDateString()] ?? 0);
            }
        }
        $result = ['type' => 'line', 'labels' => $labels, 'series' => [['name' => 'This period', 'data' => $values]]];
        if ($previous !== null) {
            $result['series'][] = ['name' => 'Comparison period', 'data' => $previousValues];
        }

        return $result;
    }
}
