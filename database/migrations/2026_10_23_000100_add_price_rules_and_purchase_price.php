<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pricing refactor.
 *  - product_variants.purchase_price: the usual / last price paid per base unit. It fills purchase
 *    orders and Purchase stock and shows margins. Profit and stock value still come from received costs.
 *  - price_rules: "when these conditions are true, charge this price" (wholesale price, a fixed price,
 *    or a percentage off the In-store price), for one product (SKU), a category, a brand or everything.
 *  - sales_invoice_lines / sales_order_lines.price_rule_id: which rule set the price, for the record.
 *  - The default price list is renamed from "Retail" to "In-store" (only if it still has the old name).
 *  - Quantity tiers on price lists (a price from N units) become product rules, and the tier prices end.
 *  - purchase_price is filled from the last goods received, else the latest opening stock cost.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->decimal('purchase_price', 18, 4)->nullable(); // per base unit
        });

        Schema::create('price_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('name', 120)->nullable();
            $table->string('scope', 20); // variant, category, brand, all
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('product_categories')->cascadeOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('match', 3)->default('all'); // all, any
            $table->jsonb('conditions');
            $table->string('action', 20); // wholesale, fixed, percent_off
            $table->decimal('value', 16, 4)->nullable(); // fixed price, or percent off
            $table->foreignId('unit_id')->nullable()->constrained()->nullOnDelete(); // the pack a fixed price is for (null: base unit)
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->index(['company_id', 'is_active', 'scope']);
            $table->index(['variant_id']);
        });
        DB::statement("ALTER TABLE price_rules ADD CONSTRAINT price_rules_scope_target CHECK (
            (scope = 'variant' AND variant_id IS NOT NULL) OR (scope = 'category' AND category_id IS NOT NULL)
            OR (scope = 'brand' AND brand_id IS NOT NULL) OR (scope = 'all'))");

        foreach (['sales_invoice_lines', 'sales_order_lines'] as $lines) {
            Schema::table($lines, function (Blueprint $table) {
                $table->foreignId('price_rule_id')->nullable()->constrained()->nullOnDelete();
            });
        }

        DB::table('price_books')->where('code', 'RETAIL')->where('name', 'Retail')->update(['name' => 'In-store', 'name_ur' => 'دکان کی قیمت']);

        $this->convertTiers();
        $this->fillPurchasePrices();
    }

    /** A price from N units on a price list becomes a rule on that product: quantity at least N -> that fixed price. */
    private function convertTiers(): void
    {
        $now = now();
        $defaultBooks = DB::table('price_books')->where('is_default', true)->pluck('id', 'company_id');
        $tiers = DB::table('prices as p')->join('price_books as b', 'b.id', '=', 'p.price_book_id')->join('variant_units as vu', 'vu.id', '=', 'p.variant_unit_id')
            ->where('p.min_quantity', '>', 1)->whereNull('p.effective_to')->where('b.kind', '!=', 'online')
            ->orderBy('p.variant_id')->orderBy('p.min_quantity')
            ->get(['p.id', 'p.company_id', 'p.variant_id', 'p.price_book_id', 'p.price', 'p.min_quantity', 'vu.unit_id', 'b.name as book']);
        $order = [];
        foreach ($tiers as $tier) {
            $conditions = [['type' => 'quantity', 'op' => 'gte', 'value' => (string) (float) $tier->min_quantity, 'unit_id' => (int) $tier->unit_id]];
            if ((int) ($defaultBooks[$tier->company_id] ?? 0) !== (int) $tier->price_book_id) {
                // A tier on another list (e.g. Wholesale) only ever applied to customers on that list.
                $conditions[] = ['type' => 'price_list', 'ids' => [(int) $tier->price_book_id]];
            }
            $order[$tier->variant_id] = ($order[$tier->variant_id] ?? 0) + 1;
            DB::table('price_rules')->insert([
                'company_id' => $tier->company_id, 'name' => "From the {$tier->book} list, ".(float) $tier->min_quantity.'+',
                'scope' => 'variant', 'variant_id' => $tier->variant_id, 'match' => 'all', 'conditions' => json_encode($conditions),
                'action' => 'fixed', 'value' => $tier->price, 'unit_id' => $tier->unit_id, 'sort_order' => -$order[$tier->variant_id], // bigger tiers first
                'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
            ]);
            DB::table('prices')->where('id', $tier->id)->update(['effective_to' => $now, 'note' => 'Moved to a price rule']);
        }
    }

    private function fillPurchasePrices(): void
    {
        DB::statement(<<<'SQL'
            UPDATE product_variants v SET purchase_price = x.cost FROM (
                SELECT DISTINCT ON (l.variant_id) l.variant_id, ROUND(l.unit_price / NULLIF(l.unit_factor, 0), 4) AS cost
                FROM goods_receipt_lines l JOIN goods_receipts r ON r.id = l.goods_receipt_id
                WHERE r.status = 'posted' AND l.unit_price > 0
                ORDER BY l.variant_id, r.business_date DESC, l.id DESC
            ) x WHERE x.variant_id = v.id AND v.purchase_price IS NULL
        SQL);
        DB::statement(<<<'SQL'
            UPDATE product_variants v SET purchase_price = x.cost FROM (
                SELECT DISTINCT ON (l.variant_id) l.variant_id, ROUND(l.unit_cost, 4) AS cost
                FROM stock_adjustment_lines l JOIN stock_adjustments a ON a.id = l.stock_adjustment_id
                WHERE a.kind = 'opening' AND a.status = 'posted' AND l.unit_cost > 0
                ORDER BY l.variant_id, a.posted_at DESC, l.id DESC
            ) x WHERE x.variant_id = v.id AND v.purchase_price IS NULL
        SQL);
    }

    public function down(): void
    {
        foreach (['sales_invoice_lines', 'sales_order_lines'] as $lines) {
            Schema::table($lines, fn (Blueprint $table) => $table->dropConstrainedForeignId('price_rule_id'));
        }
        Schema::dropIfExists('price_rules');
        Schema::table('product_variants', fn (Blueprint $table) => $table->dropColumn('purchase_price'));
        DB::table('price_books')->where('code', 'RETAIL')->where('name', 'In-store')->update(['name' => 'Retail', 'name_ur' => 'پرچون']);
    }
};
