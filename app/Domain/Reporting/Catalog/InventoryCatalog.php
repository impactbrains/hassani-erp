<?php

namespace App\Domain\Reporting\Catalog;

use App\Domain\Reporting\DefinedReport;
use App\Domain\Reporting\ReportFilters as F;
use App\Support\Decimal;
use App\Support\Settings\Settings;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;

/** Inventory and purchasing reports. */
final class InventoryCatalog
{
    /** Sellable on-hand minus active reservations, per store and variant. */
    private static function stockByVariant(F $f)
    {
        return DB::table('stock_levels as sl')->join('locations as loc', 'loc.id', '=', 'sl.location_id')->leftJoin('stock_batches as b', 'b.id', '=', 'sl.batch_id')
            ->whereIn('sl.store_id', $f->storeIds)->where('loc.type', '!=', 'transit')
            ->groupBy('sl.store_id', 'sl.variant_id')
            ->selectRaw("sl.store_id, sl.variant_id, SUM(sl.on_hand) on_hand,
                SUM(CASE WHEN loc.is_sellable AND (b.id IS NULL OR (b.status = 'available' AND (b.expiry_date IS NULL OR b.expiry_date >= CURRENT_DATE))) THEN sl.on_hand ELSE 0 END) sellable,
                SUM(CASE WHEN NOT loc.is_sellable OR (b.id IS NOT NULL AND (b.status <> 'available' OR b.expiry_date < CURRENT_DATE)) THEN sl.on_hand ELSE 0 END) blocked");
    }

    private static function valuation(F $f)
    {
        $method = (string) Settings::get('inventory.valuation_method');
        $fifo = DB::table('cost_layers')->whereIn('store_id', $f->storeIds)->where('quantity_remaining', '>', 0)->groupBy('store_id', 'variant_id')
            ->selectRaw('store_id, variant_id, SUM(quantity_remaining * unit_cost) fifo_value');

        return DB::table('variant_costs as vc')->join('product_variants as v', 'v.id', '=', 'vc.variant_id')->join('products as p', 'p.id', '=', 'v.product_id')
            ->join('stores as s', 's.id', '=', 'vc.store_id')->leftJoin('product_categories as pc', 'pc.id', '=', 'p.category_id')
            ->leftJoinSub($fifo, 'fl', fn ($j) => $j->on('fl.store_id', '=', 'vc.store_id')->on('fl.variant_id', '=', 'vc.variant_id'))
            ->whereIn('vc.store_id', $f->storeIds)->where('vc.quantity', '!=', 0)
            ->when($f->entity('category_id'), fn ($q, $id) => $q->where('p.category_id', $id))
            ->when($f->search, fn ($q, $s) => $q->where(fn ($w) => $w->where('p.name', 'ilike', "%{$s}%")->orWhere('v.sku', 'ilike', "%{$s}%")))
            ->selectRaw("s.name store, v.sku, p.name || ' – ' || v.name product, COALESCE(pc.name, '-') category, vc.quantity, vc.average_cost, ROUND(vc.quantity * vc.average_cost, 2) average_value,
                ROUND(COALESCE(fl.fifo_value, 0), 2) fifo_value, ROUND(".($method === 'fifo' ? 'COALESCE(fl.fifo_value, 0)' : 'vc.quantity * vc.average_cost').', 2) book_value, vc.last_purchase_cost');
    }

    private static function glInventory(F $f): string
    {
        return Decimal::money(DB::table('journal_lines as jl')->join('accounts as a', 'a.id', '=', 'jl.account_id')->where('a.system_key', 'inventory')
            ->where('jl.company_id', CurrentCompany::require())->whereIn('jl.store_id', $f->storeIds)->selectRaw('COALESCE(SUM(jl.debit - jl.credit),0) b')->value('b'));
    }

    /** @return list<array<string, mixed>> */
    public static function reports(): array
    {
        $money = fn (string $label, array $extra = []) => ['label' => $label, 'type' => 'money'] + $extra;
        $qty = fn (string $label) => ['label' => $label, 'type' => 'qty'];
        $cost = 'catalog.costs.view';

        return [
            [
                'key' => 'inventory.on_hand', 'section' => 'inventory', 'title' => 'Stock on hand', 'title_ur' => 'موجودہ اسٹاک', 'permission' => 'reports.inventory',
                'description' => 'Current stock of every product by store: on hand, sellable, reserved for orders, blocked (damaged, quarantined, expired) and available to sell.',
                'filters' => ['stores', 'category', 'search'],
                'definition' => ['source' => 'Live stock levels and active reservations', 'date_basis' => 'As of now', 'definitions' => ['Available' => 'Sellable − reserved. Sellable excludes non-sellable locations, quarantined or expired batches and stock in transit.']],
                'columns' => ['store' => ['label' => 'Store', 'type' => 'text'], 'sku' => ['label' => 'SKU', 'type' => 'text'], 'product' => ['label' => 'Product', 'type' => 'text'], 'product_ur' => ['label' => 'اردو نام', 'type' => 'text'], 'unit' => ['label' => 'Unit', 'type' => 'text'], 'on_hand' => $qty('On hand'), 'sellable' => $qty('Sellable'), 'reserved' => $qty('Reserved'), 'blocked' => $qty('Blocked'), 'available' => $qty('Available'), 'value' => $money('Value', ['sensitive' => 'inventory.valuation.view'])],
                'rows' => fn (F $f) => DB::query()->fromSub(self::stockByVariant($f), 'st')->join('product_variants as v', 'v.id', '=', 'st.variant_id')->join('products as p', 'p.id', '=', 'v.product_id')
                    ->join('stores as s', 's.id', '=', 'st.store_id')->leftJoin('units as u', 'u.id', '=', 'v.base_unit_id')
                    ->leftJoin('variant_costs as vc', fn ($j) => $j->on('vc.store_id', '=', 'st.store_id')->on('vc.variant_id', '=', 'st.variant_id'))
                    ->when($f->entity('category_id'), fn ($q, $id) => $q->where('p.category_id', $id))
                    ->when($f->search, fn ($q, $s) => $q->where(fn ($w) => $w->where('p.name', 'ilike', "%{$s}%")->orWhere('v.sku', 'ilike', "%{$s}%")->orWhere('p.name_ur', 'ilike', "%{$s}%")))
                    ->selectRaw("s.name store, v.sku, p.name || ' – ' || v.name product, COALESCE(v.name_ur, p.name_ur) product_ur, u.code unit, st.on_hand, st.sellable,
                        (SELECT COALESCE(SUM(r.quantity),0) FROM stock_reservations r WHERE r.store_id = st.store_id AND r.variant_id = st.variant_id AND r.status = 'active') reserved, st.blocked,
                        st.sellable - (SELECT COALESCE(SUM(r.quantity),0) FROM stock_reservations r WHERE r.store_id = st.store_id AND r.variant_id = st.variant_id AND r.status = 'active') available,
                        ROUND(st.on_hand * COALESCE(vc.average_cost, 0), 2) value"),
                'sort' => ['product', 'asc'],
            ],
            [
                'key' => 'inventory.valuation', 'section' => 'inventory', 'title' => 'Inventory valuation', 'title_ur' => 'اسٹاک کی مالیت', 'permission' => 'inventory.valuation.view',
                'description' => 'Value of stock by product under both weighted average and FIFO, with the book value reconciled to the Inventory account in the ledger.',
                'filters' => ['stores', 'category', 'search'],
                'definition' => ['source' => 'Cost records maintained by every stock movement; ledger Inventory account', 'date_basis' => 'As of now',
                    'definitions' => ['Book value' => 'Value under the method chosen in Settings → Inventory (this is what the accounts use).', 'Difference' => 'Book value − Inventory account balance. Should be zero; any amount is investigated.'],
                    'limitations' => ['Stock in transit between stores is valued in the sending store until received.']],
                'columns' => ['store' => ['label' => 'Store', 'type' => 'text'], 'sku' => ['label' => 'SKU', 'type' => 'text'], 'product' => ['label' => 'Product', 'type' => 'text'], 'category' => ['label' => 'Category', 'type' => 'text'], 'quantity' => $qty('Quantity'), 'average_cost' => $money('Average cost'), 'average_value' => $money('Value (weighted avg)'), 'fifo_value' => $money('Value (FIFO)'), 'book_value' => $money('Book value'), 'last_purchase_cost' => $money('Last purchase cost')],
                'rows' => fn (F $f) => self::valuation($f),
                'summary' => function (F $f) {
                    $book = Decimal::money(DB::query()->fromSub(self::valuation($f), 'x')->sum('book_value'));
                    $gl = self::glInventory($f);
                    $diff = Decimal::money(Decimal::sub($book, $gl));

                    return [
                        SalesCatalog::card('book', 'Stock value ('.Settings::get('inventory.valuation_method').')', $book, 'money'),
                        SalesCatalog::card('gl', 'Inventory account balance', $gl, 'money'),
                        SalesCatalog::card('difference', 'Difference', $diff, 'money', null, Decimal::isZero($diff) ? 'Reconciled' : 'Investigate: stock records and accounts disagree'),
                    ];
                },
                'insights' => fn (F $f, array $s) => Decimal::isZero((string) ($s[2]['value'] ?? '0')) ? [] : [['kind' => 'warning', 'text' => 'Stock value and the Inventory account differ by Rs '.$s[2]['value'].'. Check journal entries posted by hand to the Inventory account.']],
                'sort' => ['book_value', 'desc'],
            ],
            [
                'key' => 'inventory.movements', 'section' => 'inventory', 'title' => 'Stock movement history', 'title_ur' => 'اسٹاک کی تاریخ', 'permission' => 'reports.inventory',
                'description' => 'Every receipt, sale, return, transfer, adjustment, write-off and count change, with who did it and the cost. Movements are permanent; corrections appear as new lines.',
                'filters' => ['period', 'stores', 'product', 'search'],
                'definition' => ['source' => 'Stock movement ledger (append-only)', 'date_basis' => 'Business date of the movement'],
                'columns' => ['occurred_at' => ['label' => 'When', 'type' => 'datetime'], 'store' => ['label' => 'Store', 'type' => 'text'], 'location' => ['label' => 'Location', 'type' => 'text'], 'sku' => ['label' => 'SKU', 'type' => 'text'], 'product' => ['label' => 'Product', 'type' => 'text'], 'type' => ['label' => 'Type', 'type' => 'status'], 'quantity' => $qty('Qty'), 'unit_cost' => $money('Unit cost', ['sensitive' => $cost]), 'total_cost' => $money('Value', ['sensitive' => $cost]), 'batch' => ['label' => 'Batch', 'type' => 'text'], 'reason' => ['label' => 'Reason', 'type' => 'text'], 'user' => ['label' => 'By', 'type' => 'text'], 'reference' => ['label' => 'Document', 'type' => 'text']],
                'rows' => fn (F $f) => DB::table('stock_movements as m')->join('product_variants as v', 'v.id', '=', 'm.variant_id')->join('products as p', 'p.id', '=', 'v.product_id')
                    ->join('stores as s', 's.id', '=', 'm.store_id')->join('locations as l', 'l.id', '=', 'm.location_id')->leftJoin('stock_batches as b', 'b.id', '=', 'm.batch_id')
                    ->leftJoin('reason_codes as rc', 'rc.id', '=', 'm.reason_code_id')->leftJoin('users as u', 'u.id', '=', 'm.user_id')
                    ->whereBetween('m.business_date', [$f->from, $f->to])->whereIn('m.store_id', $f->storeIds)
                    ->when($f->entity('variant_id'), fn ($q, $id) => $q->where('m.variant_id', $id))
                    ->when($f->entity('product_id'), fn ($q, $id) => $q->where('v.product_id', $id))
                    ->when($f->option('type'), fn ($q, $t) => $q->where('m.movement_type', $t))
                    ->when($f->search, fn ($q, $s) => $q->where(fn ($w) => $w->where('p.name', 'ilike', "%{$s}%")->orWhere('v.sku', 'ilike', "%{$s}%")))
                    ->selectRaw("m.occurred_at, s.name store, l.name location, v.sku, p.name || ' – ' || v.name product, m.movement_type type, m.quantity, m.unit_cost, m.total_cost, b.batch_no batch, rc.label reason, u.name user, regexp_replace(m.source_type, '^.*\\\\', '') || ' #' || m.source_id reference"),
                'sort' => ['occurred_at', 'desc'],
            ],
            [
                'key' => 'inventory.low_stock', 'section' => 'inventory', 'title' => 'Low stock and reorder', 'title_ur' => 'کم اسٹاک', 'permission' => 'reports.inventory',
                'description' => 'Products at or below their reorder point, with average daily sales over the last 28 days and days of cover left.',
                'filters' => ['stores', 'category', 'search'],
                'definition' => ['source' => 'Stock levels, reorder rules and the last 28 days of sales', 'date_basis' => 'As of now', 'definitions' => ['Days of cover' => 'Available ÷ average daily units sold (28 days).', 'Suggested order' => 'Up to the maximum level, rounded to the order multiple.'], 'limitations' => ['Products without a reorder rule are listed when they have sold recently and have under 7 days of cover.']],
                'columns' => ['store' => ['label' => 'Store', 'type' => 'text'], 'sku' => ['label' => 'SKU', 'type' => 'text'], 'product' => ['label' => 'Product', 'type' => 'text'], 'available' => $qty('Available'), 'reorder_point' => $qty('Reorder at'), 'max_quantity' => $qty('Max'), 'daily_sales' => ['label' => 'Avg daily sales', 'type' => 'decimal'], 'days_cover' => ['label' => 'Days of cover', 'type' => 'decimal'], 'suggested' => $qty('Suggested order'), 'supplier' => ['label' => 'Preferred supplier', 'type' => 'text']],
                'rows' => function (F $f) {
                    $sold = DB::table('stock_movements')->whereIn('store_id', $f->storeIds)->whereIn('movement_type', ['sale', 'bundle_consume', 'sale_return', 'bundle_return'])
                        ->where('business_date', '>=', now()->subDays(28)->toDateString())->groupBy('store_id', 'variant_id')->selectRaw('store_id, variant_id, -SUM(quantity) / 28.0 daily');
                    $res = DB::table('stock_reservations')->where('status', 'active')->groupBy('store_id', 'variant_id')->selectRaw('store_id, variant_id, SUM(quantity) q');

                    return DB::query()->fromSub(DB::query()->fromSub(self::stockByVariant($f), 'st')
                        ->leftJoinSub($res, 'r', fn ($j) => $j->on('r.store_id', '=', 'st.store_id')->on('r.variant_id', '=', 'st.variant_id'))
                        ->leftJoinSub($sold, 'd', fn ($j) => $j->on('d.store_id', '=', 'st.store_id')->on('d.variant_id', '=', 'st.variant_id'))
                        ->leftJoin('replenishment_policies as rp', fn ($j) => $j->on('rp.store_id', '=', 'st.store_id')->on('rp.variant_id', '=', 'st.variant_id')->where('rp.is_active', true))
                        ->join('product_variants as v', 'v.id', '=', 'st.variant_id')->join('products as p', 'p.id', '=', 'v.product_id')->join('stores as s', 's.id', '=', 'st.store_id')
                        ->leftJoin('contacts as sup', 'sup.id', '=', 'rp.preferred_supplier_id')
                        ->when($f->entity('category_id'), fn ($q, $id) => $q->where('p.category_id', $id))
                        ->when($f->search, fn ($q, $s) => $q->where('p.name', 'ilike', "%{$s}%"))
                        ->selectRaw("s.name store, v.sku, p.name || ' – ' || v.name product, st.sellable - COALESCE(r.q, 0) available, rp.reorder_point, rp.max_quantity, ROUND(COALESCE(d.daily, 0), 2) daily_sales,
                            CASE WHEN COALESCE(d.daily, 0) > 0 THEN ROUND((st.sellable - COALESCE(r.q, 0)) / d.daily, 1) END days_cover,
                            CASE WHEN rp.max_quantity IS NOT NULL THEN CEIL(GREATEST(0, rp.max_quantity - (st.sellable - COALESCE(r.q, 0))) / GREATEST(COALESCE(rp.order_multiple, 1), 1)) * GREATEST(COALESCE(rp.order_multiple, 1), 1) END suggested,
                            sup.name supplier, rp.id policy_id"), 'x')
                        ->whereRaw('(policy_id IS NOT NULL AND available <= reorder_point) OR (policy_id IS NULL AND daily_sales > 0 AND days_cover < 7)')
                        ->selectRaw('store, sku, product, available, reorder_point, max_quantity, daily_sales, days_cover, suggested, supplier');
                },
                'sort' => ['days_cover', 'asc'],
            ],
            [
                'key' => 'inventory.expiry', 'section' => 'inventory', 'title' => 'Expiry and near-expiry', 'title_ur' => 'میعاد ختم', 'permission' => 'reports.inventory',
                'description' => 'Batches already expired or expiring soon, with quantity and value at risk, so they can be sold first, returned or written off.',
                'filters' => ['stores', 'search'],
                'definition' => ['source' => 'Stock levels by batch', 'date_basis' => 'As of today', 'definitions' => ['Near expiry' => 'Within the number of days set in Settings → Inventory.']],
                'columns' => ['store' => ['label' => 'Store', 'type' => 'text'], 'product' => ['label' => 'Product', 'type' => 'text'], 'batch' => ['label' => 'Batch', 'type' => 'text'], 'expiry_date' => ['label' => 'Expires', 'type' => 'date'], 'days_left' => ['label' => 'Days left', 'type' => 'int'], 'location' => ['label' => 'Location', 'type' => 'text'], 'quantity' => $qty('Qty'), 'value' => $money('Value at risk', ['sensitive' => $cost]), 'status' => ['label' => 'Status', 'type' => 'status']],
                'rows' => function (F $f) {
                    $days = (int) Settings::get('inventory.near_expiry_days');

                    return DB::table('stock_levels as sl')->join('stock_batches as b', 'b.id', '=', 'sl.batch_id')->join('product_variants as v', 'v.id', '=', 'sl.variant_id')->join('products as p', 'p.id', '=', 'v.product_id')
                        ->join('stores as s', 's.id', '=', 'sl.store_id')->join('locations as l', 'l.id', '=', 'sl.location_id')
                        ->leftJoin('variant_costs as vc', fn ($j) => $j->on('vc.store_id', '=', 'sl.store_id')->on('vc.variant_id', '=', 'sl.variant_id'))
                        ->whereIn('sl.store_id', $f->storeIds)->where('sl.on_hand', '>', 0)->whereNotNull('b.expiry_date')->where('b.expiry_date', '<=', now()->addDays($days)->toDateString())
                        ->when($f->search, fn ($q, $s) => $q->where('p.name', 'ilike', "%{$s}%"))
                        ->selectRaw("s.name store, p.name || ' – ' || v.name product, b.batch_no batch, b.expiry_date, (b.expiry_date - CURRENT_DATE) days_left, l.name location, sl.on_hand quantity, ROUND(sl.on_hand * COALESCE(vc.average_cost, 0), 2) value,
                            CASE WHEN b.expiry_date < CURRENT_DATE THEN 'expired' ELSE 'near_expiry' END status");
                },
                'sort' => ['expiry_date', 'asc'],
            ],
            [
                'key' => 'inventory.slow_moving', 'section' => 'inventory', 'title' => 'Slow-moving and dead stock', 'permission' => 'reports.inventory',
                'description' => 'Stock that has not sold for a long time, tying up money and shelf space.',
                'filters' => ['stores', 'category'],
                'definition' => ['source' => 'Stock on hand and the last sale of each product in the store', 'date_basis' => 'As of now', 'definitions' => ['Dead stock' => 'No sale for 90 days or more (or never sold).', 'Slow' => 'No sale for 30–89 days.']],
                'columns' => ['store' => ['label' => 'Store', 'type' => 'text'], 'sku' => ['label' => 'SKU', 'type' => 'text'], 'product' => ['label' => 'Product', 'type' => 'text'], 'on_hand' => $qty('On hand'), 'value' => $money('Value', ['sensitive' => $cost]), 'last_sold' => ['label' => 'Last sold', 'type' => 'date'], 'days_since' => ['label' => 'Days since sale', 'type' => 'int'], 'class' => ['label' => 'Class', 'type' => 'status']],
                'rows' => fn (F $f) => DB::query()->fromSub(DB::table('variant_costs as vc')->join('product_variants as v', 'v.id', '=', 'vc.variant_id')->join('products as p', 'p.id', '=', 'v.product_id')->join('stores as s', 's.id', '=', 'vc.store_id')
                    ->whereIn('vc.store_id', $f->storeIds)->where('vc.quantity', '>', 0)->when($f->entity('category_id'), fn ($q, $id) => $q->where('p.category_id', $id))
                    ->selectRaw("s.name store, v.sku, p.name || ' – ' || v.name product, vc.quantity on_hand, ROUND(vc.quantity * vc.average_cost, 2) value,
                        (SELECT MAX(business_date) FROM stock_movements m WHERE m.store_id = vc.store_id AND m.variant_id = vc.variant_id AND m.movement_type = 'sale') last_sold"), 'x')
                    ->whereRaw('last_sold IS NULL OR last_sold < CURRENT_DATE - 30')
                    ->selectRaw("store, sku, product, on_hand, value, last_sold, (CURRENT_DATE - last_sold) days_since, CASE WHEN last_sold IS NULL OR last_sold < CURRENT_DATE - 90 THEN 'dead' ELSE 'slow' END class"),
                'sort' => ['value', 'desc'],
            ],
            [
                'key' => 'inventory.shrinkage', 'section' => 'inventory', 'title' => 'Shrinkage and write-offs', 'title_ur' => 'نقصان', 'permission' => 'reports.inventory',
                'description' => 'Stock lost to damage, expiry, theft and count differences, by reason and product, with approver. Read as exceptions to review.',
                'filters' => ['period', 'stores', 'search'],
                'definition' => ['source' => 'Write-off, adjustment and count-variance movements', 'date_basis' => 'Business date', 'definitions' => ['Shrinkage rate' => 'Value lost ÷ cost of goods sold in the period.']],
                'columns' => ['date' => ['label' => 'Date', 'type' => 'date'], 'store' => ['label' => 'Store', 'type' => 'text'], 'product' => ['label' => 'Product', 'type' => 'text'], 'type' => ['label' => 'Type', 'type' => 'status'], 'reason' => ['label' => 'Reason', 'type' => 'text'], 'quantity' => $qty('Qty'), 'value' => $money('Value', ['sensitive' => $cost]), 'by' => ['label' => 'By', 'type' => 'text'], 'approved_by' => ['label' => 'Approved by', 'type' => 'text']],
                'rows' => fn (F $f) => DB::table('stock_movements as m')->join('product_variants as v', 'v.id', '=', 'm.variant_id')->join('products as p', 'p.id', '=', 'v.product_id')->join('stores as s', 's.id', '=', 'm.store_id')
                    ->leftJoin('reason_codes as rc', 'rc.id', '=', 'm.reason_code_id')->leftJoin('users as u', 'u.id', '=', 'm.user_id')->leftJoin('users as a', 'a.id', '=', 'm.approved_by')
                    ->whereIn('m.movement_type', ['write_off', 'adjustment', 'count_variance'])->whereBetween('m.business_date', [$f->from, $f->to])->whereIn('m.store_id', $f->storeIds)
                    ->when($f->search, fn ($q, $s) => $q->where('p.name', 'ilike', "%{$s}%"))
                    ->selectRaw("m.business_date date, s.name store, p.name || ' – ' || v.name product, m.movement_type type, rc.label reason, m.quantity, m.total_cost value, u.name by, a.name approved_by"),
                'summary' => function (F $f) {
                    $lost = DB::table('stock_movements')->whereIn('movement_type', ['write_off', 'adjustment', 'count_variance'])->whereBetween('business_date', [$f->from, $f->to])->whereIn('store_id', $f->storeIds)->selectRaw('COALESCE(SUM(-total_cost),0) v')->value('v');
                    $cogs = DB::table('stock_movements')->whereIn('movement_type', ['sale', 'bundle_consume'])->whereBetween('business_date', [$f->from, $f->to])->whereIn('store_id', $f->storeIds)->selectRaw('COALESCE(SUM(-total_cost),0) v')->value('v');

                    return [SalesCatalog::card('lost', 'Net value lost', Decimal::money($lost), 'money'), SalesCatalog::card('rate', 'Shrinkage rate', Decimal::isPositive($cogs) ? Decimal::round(Decimal::div(Decimal::mul($lost, '100'), $cogs, 4), 2) : null, 'percent', null, 'of cost of goods sold')];
                },
                'sort' => ['date', 'desc'],
            ],
            [
                'key' => 'inventory.counts', 'section' => 'inventory', 'title' => 'Stock count results', 'title_ur' => 'گنتی', 'permission' => 'reports.inventory',
                'description' => 'Each count line: expected vs counted, sales during the count, the variance and its value. Do the stock counts match?',
                'filters' => ['period', 'stores'],
                'definition' => ['source' => 'Stock counts and their lines', 'date_basis' => 'Count start (snapshot) date', 'definitions' => ['Variance' => 'Counted − (expected at snapshot + movements since snapshot).', 'Accuracy' => 'Lines with no variance ÷ lines counted.']],
                'columns' => ['count' => ['label' => 'Count', 'type' => 'text'], 'date' => ['label' => 'Date', 'type' => 'date'], 'status' => ['label' => 'Status', 'type' => 'status'], 'product' => ['label' => 'Product', 'type' => 'text'], 'location' => ['label' => 'Location', 'type' => 'text'], 'expected' => $qty('Expected'), 'moved' => $qty('Moved during count'), 'counted' => $qty('Counted'), 'variance' => $qty('Variance'), 'value' => $money('Variance value', ['sensitive' => $cost]), 'reason' => ['label' => 'Reason', 'type' => 'text']],
                'rows' => fn (F $f) => DB::table('stock_count_lines as cl')->join('stock_counts as c', 'c.id', '=', 'cl.stock_count_id')->join('product_variants as v', 'v.id', '=', 'cl.variant_id')->join('products as p', 'p.id', '=', 'v.product_id')
                    ->join('locations as l', 'l.id', '=', 'cl.location_id')->leftJoin('reason_codes as rc', 'rc.id', '=', 'cl.reason_code_id')
                    ->whereIn('c.store_id', $f->storeIds)->whereBetween(DB::raw("(c.snapshot_at AT TIME ZONE '{$f->timezone}')::date"), [$f->from, $f->to])
                    ->selectRaw("c.number count, (c.snapshot_at AT TIME ZONE '{$f->timezone}')::date date, c.status, p.name || ' – ' || v.name product, l.name location, cl.expected_quantity expected, cl.movement_since_snapshot moved,
                        COALESCE(cl.second_count_quantity, cl.counted_quantity) counted, cl.variance_quantity variance, cl.variance_value value, rc.label reason"),
                'summary' => function (F $f) {
                    $r = DB::table('stock_count_lines as cl')->join('stock_counts as c', 'c.id', '=', 'cl.stock_count_id')->whereIn('c.store_id', $f->storeIds)->where('c.status', 'posted')
                        ->whereBetween(DB::raw("(c.snapshot_at AT TIME ZONE '{$f->timezone}')::date"), [$f->from, $f->to])
                        ->selectRaw('COUNT(*) n, SUM(CASE WHEN COALESCE(variance_quantity,0) = 0 THEN 1 ELSE 0 END) exact, COALESCE(SUM(variance_value),0) v, COALESCE(SUM(ABS(variance_value)),0) av')->first();

                    return [SalesCatalog::card('accuracy', 'Count accuracy', $r->n > 0 ? round(100 * $r->exact / $r->n, 1) : null, 'percent', null, "{$r->exact} of {$r->n} lines matched"),
                        SalesCatalog::card('net', 'Net variance value', Decimal::money($r->v), 'money'), SalesCatalog::card('abs', 'Absolute variance value', Decimal::money($r->av), 'money')];
                },
                'sort' => ['date', 'desc'],
            ],
            [
                'key' => 'inventory.turnover', 'section' => 'inventory', 'title' => 'Stock turnover and days on hand', 'permission' => 'reports.inventory',
                'description' => 'How fast each category turns its stock: cost of goods sold in the period against current stock value.',
                'filters' => ['period', 'stores'],
                'definition' => ['source' => 'Sale movements (cost) and current stock value', 'date_basis' => 'Period for sales; now for stock', 'definitions' => ['Turnover (annualised)' => 'COGS ÷ current stock value × 365 ÷ days in period.', 'Days on hand' => 'Current stock value ÷ average daily COGS.'], 'limitations' => ['Uses closing stock instead of an average balance.']],
                'columns' => ['category' => ['label' => 'Category', 'type' => 'text'], 'cogs' => $money('Cost of goods sold'), 'stock_value' => $money('Stock value now'), 'turnover' => ['label' => 'Turnover / year', 'type' => 'decimal'], 'days_on_hand' => ['label' => 'Days on hand', 'type' => 'decimal']],
                'rows' => function (F $f) {
                    $days = $f->days();
                    $cogs = DB::table('stock_movements as m')->join('product_variants as v', 'v.id', '=', 'm.variant_id')->join('products as p', 'p.id', '=', 'v.product_id')
                        ->whereIn('m.movement_type', ['sale', 'bundle_consume', 'sale_return', 'bundle_return'])->whereBetween('m.business_date', [$f->from, $f->to])->whereIn('m.store_id', $f->storeIds)
                        ->groupBy('p.category_id')->selectRaw('p.category_id, -SUM(m.total_cost) cogs');
                    $stock = DB::table('variant_costs as vc')->join('product_variants as v', 'v.id', '=', 'vc.variant_id')->join('products as p', 'p.id', '=', 'v.product_id')
                        ->whereIn('vc.store_id', $f->storeIds)->groupBy('p.category_id')->selectRaw('p.category_id, SUM(vc.quantity * vc.average_cost) stock_value');

                    return DB::query()->fromSub($stock, 's')->joinSub($cogs, 'c', 'c.category_id', '=', 's.category_id', 'full')
                        ->leftJoin('product_categories as pc', 'pc.id', '=', DB::raw('COALESCE(s.category_id, c.category_id)'))
                        ->selectRaw("COALESCE(pc.name, 'Not set') category, ROUND(COALESCE(c.cogs, 0), 2) cogs, ROUND(COALESCE(s.stock_value, 0), 2) stock_value,
                            CASE WHEN COALESCE(s.stock_value, 0) > 0 THEN ROUND(COALESCE(c.cogs, 0) / s.stock_value * 365.0 / {$days}, 2) END turnover,
                            CASE WHEN COALESCE(c.cogs, 0) > 0 THEN ROUND(COALESCE(s.stock_value, 0) / (c.cogs / {$days}), 1) END days_on_hand");
                },
                'sort' => ['cogs', 'desc'],
            ],
            [
                'key' => 'inventory.transfers', 'section' => 'inventory', 'title' => 'Transfers between stores', 'permission' => 'reports.inventory',
                'description' => 'Stock sent between stores and godowns, what arrived, and anything still in transit or short.',
                'filters' => ['period', 'stores'],
                'definition' => ['source' => 'Stock transfers', 'date_basis' => 'Date requested'],
                'columns' => ['number' => ['label' => 'Transfer', 'type' => 'text'], 'requested' => ['label' => 'Requested', 'type' => 'date'], 'from_store' => ['label' => 'From', 'type' => 'text'], 'to_store' => ['label' => 'To', 'type' => 'text'], 'status' => ['label' => 'Status', 'type' => 'status'], 'dispatched_at' => ['label' => 'Sent', 'type' => 'datetime'], 'received_at' => ['label' => 'Received', 'type' => 'datetime'], 'requested_by' => ['label' => 'Requested by', 'type' => 'text']],
                'rows' => fn (F $f) => DB::table('stock_transfers as t')->join('stores as a', 'a.id', '=', 't.from_store_id')->join('stores as b', 'b.id', '=', 't.to_store_id')->leftJoin('users as u', 'u.id', '=', 't.requested_by')
                    ->where(fn ($q) => $q->whereIn('t.from_store_id', $f->storeIds)->orWhereIn('t.to_store_id', $f->storeIds))
                    ->whereBetween(DB::raw("(t.created_at AT TIME ZONE '{$f->timezone}')::date"), [$f->from, $f->to])
                    ->selectRaw("t.number, (t.created_at AT TIME ZONE '{$f->timezone}')::date requested, a.name from_store, b.name to_store, t.status, t.dispatched_at, t.received_at, u.name requested_by"),
                'sort' => ['requested', 'desc'],
            ],
            // ---------- purchasing ----------
            [
                'key' => 'purchasing.register', 'section' => 'purchasing', 'title' => 'Purchases register', 'title_ur' => 'خریداری', 'permission' => 'reports.purchasing',
                'description' => 'Goods received from suppliers with value, landed costs and damage, by supplier and date.',
                'filters' => ['period', 'stores', 'supplier'],
                'definition' => ['source' => 'Posted goods receipts', 'date_basis' => 'Date received'],
                'columns' => ['number' => ['label' => 'GRN', 'type' => 'text'], 'date' => ['label' => 'Date', 'type' => 'date'], 'store' => ['label' => 'Store', 'type' => 'text'], 'supplier' => ['label' => 'Supplier', 'type' => 'text'], 'po' => ['label' => 'PO', 'type' => 'text'], 'supplier_reference' => ['label' => 'Supplier ref.', 'type' => 'text'], 'goods_value' => $money('Goods value', ['sensitive' => $cost]), 'landed' => $money('Freight / loading', ['sensitive' => $cost]), 'damaged' => $qty('Damaged qty'), 'received_by' => ['label' => 'Received by', 'type' => 'text']],
                'rows' => fn (F $f) => DB::table('goods_receipts as g')->join('contacts as s', 's.id', '=', 'g.supplier_id')->join('stores as st', 'st.id', '=', 'g.store_id')->leftJoin('purchase_orders as po', 'po.id', '=', 'g.purchase_order_id')->leftJoin('users as u', 'u.id', '=', 'g.received_by')
                    ->where('g.status', 'posted')->whereBetween('g.business_date', [$f->from, $f->to])->whereIn('g.store_id', $f->storeIds)->when($f->entity('supplier_id'), fn ($q, $id) => $q->where('g.supplier_id', $id))
                    ->selectRaw('g.number, g.business_date date, st.name store, s.name supplier, po.number po, g.supplier_reference, g.goods_value, g.landed_cost_total landed, (SELECT COALESCE(SUM(quantity_damaged * unit_factor),0) FROM goods_receipt_lines WHERE goods_receipt_id = g.id) damaged, u.name received_by'),
                'sort' => ['date', 'desc'],
            ],
            [
                'key' => 'purchasing.suppliers', 'section' => 'purchasing', 'title' => 'Supplier performance', 'permission' => 'reports.purchasing',
                'description' => 'For each supplier: orders, fill rate, on-time delivery, damaged and returned goods, and what we owe.',
                'filters' => ['period', 'stores'],
                'definition' => ['source' => 'Purchase orders, receipts, returns and bills', 'date_basis' => 'Order date (fill rate) / receipt date', 'definitions' => ['Fill rate' => 'Quantity received ÷ quantity ordered on closed or received orders.', 'On time' => 'Receipts on or before the expected date.']],
                'columns' => ['supplier' => ['label' => 'Supplier', 'type' => 'text'], 'orders' => ['label' => 'POs', 'type' => 'int'], 'ordered_value' => $money('Ordered'), 'fill_rate' => ['label' => 'Fill rate %', 'type' => 'percent'], 'on_time' => ['label' => 'On time %', 'type' => 'percent'], 'damaged' => $qty('Damaged'), 'returned' => $money('Returned'), 'payable' => $money('We owe')],
                'rows' => fn (F $f) => DB::table('contacts as s')->where('s.company_id', CurrentCompany::require())->whereIn('s.type', ['supplier'])
                    ->selectRaw('s.name supplier')
                    ->selectSub(fn ($q) => $q->from('purchase_orders')->whereColumn('supplier_id', 's.id')->whereIn('store_id', $f->storeIds)->whereBetween('order_date', [$f->from, $f->to])->whereNotIn('status', ['draft', 'cancelled'])->selectRaw('COUNT(*)'), 'orders')
                    ->selectSub(fn ($q) => $q->from('purchase_orders')->whereColumn('supplier_id', 's.id')->whereIn('store_id', $f->storeIds)->whereBetween('order_date', [$f->from, $f->to])->whereNotIn('status', ['draft', 'cancelled'])->selectRaw('COALESCE(SUM(total),0)'), 'ordered_value')
                    ->selectSub(fn ($q) => $q->from('purchase_order_lines as l')->join('purchase_orders as o', 'o.id', '=', 'l.purchase_order_id')->whereColumn('o.supplier_id', 's.id')->whereIn('o.store_id', $f->storeIds)->whereBetween('o.order_date', [$f->from, $f->to])->whereIn('o.status', ['partially_received', 'received', 'closed'])->selectRaw('ROUND(100.0 * SUM(l.base_quantity_received) / NULLIF(SUM(l.base_quantity), 0), 1)'), 'fill_rate')
                    ->selectSub(fn ($q) => $q->from('goods_receipts as g')->join('purchase_orders as o', 'o.id', '=', 'g.purchase_order_id')->whereColumn('g.supplier_id', 's.id')->whereIn('g.store_id', $f->storeIds)->where('g.status', 'posted')->whereBetween('g.business_date', [$f->from, $f->to])->whereNotNull('o.expected_date')->selectRaw('ROUND(100.0 * SUM(CASE WHEN g.business_date <= o.expected_date THEN 1 ELSE 0 END) / NULLIF(COUNT(*), 0), 1)'), 'on_time')
                    ->selectSub(fn ($q) => $q->from('goods_receipt_lines as l')->join('goods_receipts as g', 'g.id', '=', 'l.goods_receipt_id')->whereColumn('g.supplier_id', 's.id')->whereIn('g.store_id', $f->storeIds)->where('g.status', 'posted')->whereBetween('g.business_date', [$f->from, $f->to])->selectRaw('COALESCE(SUM(l.quantity_damaged * l.unit_factor),0)'), 'damaged')
                    ->selectSub(fn ($q) => $q->from('supplier_returns')->whereColumn('supplier_id', 's.id')->whereIn('store_id', $f->storeIds)->where('status', 'posted')->whereBetween('business_date', [$f->from, $f->to])->selectRaw('COALESCE(SUM(total),0)'), 'returned')
                    ->selectSub(fn ($q) => $q->from('journal_lines as jl')->join('accounts as a', 'a.id', '=', 'jl.account_id')->where('a.system_key', 'accounts_payable')->whereColumn('jl.contact_id', 's.id')->selectRaw('COALESCE(SUM(jl.credit - jl.debit),0)'), 'payable'),
                'sort' => ['ordered_value', 'desc'],
            ],
            [
                'key' => 'purchasing.ap_aging', 'section' => 'purchasing', 'title' => 'Payables aging', 'title_ur' => 'سپلائر واجبات', 'permission' => 'reports.purchasing',
                'description' => 'Unpaid supplier bills by how long they are past due.',
                'filters' => ['stores', 'search'],
                'definition' => ['source' => 'Posted supplier bills (total − paid − credited)', 'date_basis' => 'Days past due as of today'],
                'columns' => ['supplier' => ['label' => 'Supplier', 'type' => 'text'], 'current' => $money('Not yet due'), 'd1_30' => $money('1–30 days'), 'd31_60' => $money('31–60 days'), 'd61_90' => $money('61–90 days'), 'over_90' => $money('Over 90 days'), 'total' => $money('Total')],
                'rows' => function (F $f) {
                    $bal = '(b.total - b.amount_paid - b.amount_credited)';
                    $age = '(CURRENT_DATE - COALESCE(b.due_date, b.bill_date))';

                    return DB::table('supplier_bills as b')->join('contacts as c', 'c.id', '=', 'b.supplier_id')->where('b.status', 'posted')->whereIn('b.store_id', $f->storeIds)->whereRaw("{$bal} > 0")
                        ->when($f->search, fn ($q, $s) => $q->where('c.name', 'ilike', "%{$s}%"))->groupBy('c.id', 'c.name')
                        ->selectRaw("c.name supplier, SUM(CASE WHEN {$age} <= 0 THEN {$bal} ELSE 0 END) current, SUM(CASE WHEN {$age} BETWEEN 1 AND 30 THEN {$bal} ELSE 0 END) d1_30,
                            SUM(CASE WHEN {$age} BETWEEN 31 AND 60 THEN {$bal} ELSE 0 END) d31_60, SUM(CASE WHEN {$age} BETWEEN 61 AND 90 THEN {$bal} ELSE 0 END) d61_90, SUM(CASE WHEN {$age} > 90 THEN {$bal} ELSE 0 END) over_90, SUM({$bal}) total");
                },
                'sort' => ['total', 'desc'],
            ],
            [
                'key' => 'purchasing.cost_changes', 'section' => 'purchasing', 'title' => 'Purchase cost changes', 'permission' => 'reports.purchasing',
                'description' => 'Each product received at a different unit cost from the previous receipt, with the change and the current selling margin.',
                'filters' => ['period', 'stores', 'search'],
                'definition' => ['source' => 'Goods receipt lines (base unit landed cost)', 'date_basis' => 'Receipt date'],
                'columns' => ['date' => ['label' => 'Date', 'type' => 'date'], 'supplier' => ['label' => 'Supplier', 'type' => 'text'], 'product' => ['label' => 'Product', 'type' => 'text'], 'previous_cost' => $money('Previous cost', ['sensitive' => $cost]), 'new_cost' => $money('New cost', ['sensitive' => $cost]), 'change_percent' => ['label' => 'Change %', 'type' => 'percent']],
                'rows' => fn (F $f, DefinedReport $r) => DB::query()->fromSub(DB::table('goods_receipt_lines as l')->join('goods_receipts as g', 'g.id', '=', 'l.goods_receipt_id')->join('contacts as s', 's.id', '=', 'g.supplier_id')
                    ->join('product_variants as v', 'v.id', '=', 'l.variant_id')->join('products as p', 'p.id', '=', 'v.product_id')->where('g.status', 'posted')->whereIn('g.store_id', $f->storeIds)
                    ->when($f->search, fn ($q, $s) => $q->where('p.name', 'ilike', "%{$s}%"))
                    ->selectRaw("g.business_date date, s.name supplier, p.name || ' – ' || v.name product, LAG(l.base_unit_cost) OVER (PARTITION BY l.variant_id, g.store_id ORDER BY g.received_at, l.id) previous_cost, l.base_unit_cost new_cost"), 'x')
                    ->whereBetween('date', [$f->from, $f->to])->whereNotNull('previous_cost')->whereColumn('previous_cost', '!=', 'new_cost')
                    ->selectRaw('date, supplier, product, previous_cost, new_cost, '.$r->pctSql('new_cost - previous_cost', 'previous_cost').' change_percent'),
                'sort' => ['date', 'desc'],
            ],
        ];
    }
}
