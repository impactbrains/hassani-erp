<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Inventory: locations, batches, the immutable stock ledger, the locked
 * stock-level projection, FIFO cost layers and weighted-average costs,
 * reservations, adjustments, transfers, counts, repacking and
 * replenishment policies.
 *
 * Integrity rules enforced by the database itself:
 *  - stock_movements rows can never be updated or deleted (trigger);
 *  - stock_levels.on_hand can never go below zero (CHECK);
 *  - cost layer remaining quantity stays within [0, quantity_in] (CHECK).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('locations')->restrictOnDelete();
            $table->string('code', 30);
            $table->string('name');
            $table->string('name_ur')->nullable();
            // sales_floor, storage, godown, zone, aisle, rack, shelf, bin,
            // quarantine, damaged, returns, transit
            $table->string('type', 20);
            $table->boolean('is_sellable')->default(false);
            $table->boolean('is_default_receiving')->default(false);
            $table->boolean('is_default_selling')->default(false);
            $table->decimal('capacity_units', 18, 3)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->unique(['store_id', 'code']);
        });

        Schema::create('stock_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->string('batch_no', 60);
            $table->date('expiry_date')->nullable();
            $table->date('manufactured_on')->nullable();
            $table->foreignId('supplier_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->string('status', 20)->default('available'); // available, quarantined, recalled
            $table->string('status_reason')->nullable();
            $table->timestampsTz();
            $table->unique(['company_id', 'variant_id', 'batch_no']);
            $table->index(['company_id', 'expiry_date']);
        });

        Schema::create('stock_levels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->foreignId('variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->foreignId('batch_id')->nullable()->constrained('stock_batches')->restrictOnDelete();
            $table->decimal('on_hand', 18, 3)->default(0);
            $table->timestampTz('updated_at')->nullable();
            $table->index(['store_id', 'variant_id']);
            $table->index(['company_id', 'variant_id']);
        });
        DB::statement('CREATE UNIQUE INDEX stock_levels_unique_slot ON stock_levels (location_id, variant_id, COALESCE(batch_id, 0))');
        DB::statement('ALTER TABLE stock_levels ADD CONSTRAINT stock_levels_on_hand_non_negative CHECK (on_hand >= 0)');

        Schema::create('stock_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->foreignId('variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->decimal('quantity', 18, 3);
            $table->string('source_type');
            $table->unsignedBigInteger('source_id');
            $table->string('status', 20)->default('active'); // active, released, fulfilled
            $table->timestampTz('expires_at')->nullable();
            $table->timestampsTz();
            $table->index(['store_id', 'variant_id', 'status']);
            $table->index(['source_type', 'source_id']);
        });

        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->foreignId('variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->foreignId('batch_id')->nullable()->constrained('stock_batches')->restrictOnDelete();
            $table->decimal('quantity', 18, 3); // signed, base unit
            // receipt, sale, sale_return, purchase_return, adjustment, write_off,
            // transfer_out, transfer_in, count_variance, repack_consume,
            // repack_produce, bundle_consume, bundle_return, quarantine_out,
            // quarantine_in, opening, reversal
            $table->string('movement_type', 30);
            $table->foreignId('reason_code_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('unit_cost', 18, 6)->nullable();
            $table->decimal('total_cost', 16, 2)->nullable(); // signed, matches quantity sign
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->unsignedBigInteger('source_line_id')->nullable();
            $table->foreignId('reverses_movement_id')->nullable()->constrained('stock_movements')->restrictOnDelete();
            $table->uuid('operation_id');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note')->nullable();
            $table->timestampTz('occurred_at');
            $table->date('business_date');
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['company_id', 'variant_id', 'occurred_at']);
            $table->index(['location_id', 'variant_id']);
            $table->index(['source_type', 'source_id']);
            $table->index(['company_id', 'business_date', 'movement_type']);
            $table->index(['operation_id']);
        });
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION forbid_ledger_mutation() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'Ledger table % is append-only; post a reversal instead', TG_TABLE_NAME;
            END;
            $$ LANGUAGE plpgsql
        SQL);
        DB::statement('CREATE TRIGGER stock_movements_append_only BEFORE UPDATE OR DELETE ON stock_movements FOR EACH ROW EXECUTE FUNCTION forbid_ledger_mutation()');

        Schema::create('cost_layers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->foreignId('variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->foreignId('movement_id')->constrained('stock_movements')->restrictOnDelete();
            $table->decimal('quantity_in', 18, 3);
            $table->decimal('quantity_remaining', 18, 3);
            $table->decimal('unit_cost', 18, 6);
            $table->timestampTz('received_at');
            $table->timestampsTz();
            $table->index(['store_id', 'variant_id', 'received_at']);
        });
        DB::statement('ALTER TABLE cost_layers ADD CONSTRAINT cost_layers_remaining_bounds CHECK (quantity_remaining >= 0 AND quantity_remaining <= quantity_in)');

        Schema::create('cost_layer_consumptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cost_layer_id')->constrained()->restrictOnDelete();
            $table->foreignId('movement_id')->constrained('stock_movements')->restrictOnDelete();
            $table->decimal('quantity', 18, 3);
            $table->decimal('unit_cost', 18, 6);
            $table->timestampTz('created_at')->useCurrent();
            $table->index('movement_id');
        });

        Schema::create('variant_costs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->foreignId('variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->decimal('quantity', 18, 3)->default(0);
            $table->decimal('average_cost', 18, 6)->default(0);
            $table->decimal('last_purchase_cost', 18, 6)->nullable();
            $table->timestampTz('updated_at')->nullable();
            $table->unique(['store_id', 'variant_id']);
        });

        Schema::create('stock_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->string('number', 30);
            $table->string('kind', 20); // adjustment, write_off, opening, quarantine, release
            $table->string('status', 20)->default('draft'); // draft, pending_approval, approved, posted, rejected
            $table->foreignId('reason_code_id')->nullable()->constrained()->nullOnDelete();
            $table->text('note')->nullable();
            $table->decimal('total_value', 16, 2)->default(0);
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('second_approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('posted_at')->nullable();
            $table->date('business_date')->nullable();
            $table->timestampsTz();
            $table->unique(['company_id', 'number']);
        });

        Schema::create('stock_adjustment_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_adjustment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->foreignId('to_location_id')->nullable()->constrained('locations')->restrictOnDelete();
            $table->foreignId('batch_id')->nullable()->constrained('stock_batches')->restrictOnDelete();
            $table->string('batch_no', 60)->nullable();
            $table->date('expiry_date')->nullable();
            $table->decimal('quantity', 18, 3); // signed base units
            $table->decimal('unit_cost', 18, 6)->nullable(); // for opening stock / increases
            $table->foreignId('reason_code_id')->nullable()->constrained()->nullOnDelete();
            $table->string('note')->nullable();
        });

        Schema::create('stock_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('number', 30);
            $table->foreignId('from_store_id')->constrained('stores')->restrictOnDelete();
            $table->foreignId('from_location_id')->constrained('locations')->restrictOnDelete();
            $table->foreignId('to_store_id')->constrained('stores')->restrictOnDelete();
            $table->foreignId('to_location_id')->constrained('locations')->restrictOnDelete();
            // draft, requested, approved, dispatched, partially_received, received, rejected, cancelled
            $table->string('status', 20)->default('draft');
            $table->text('note')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('dispatched_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('dispatched_at')->nullable();
            $table->timestampTz('received_at')->nullable();
            $table->timestampsTz();
            $table->unique(['company_id', 'number']);
        });

        Schema::create('stock_transfer_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_transfer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->foreignId('batch_id')->nullable()->constrained('stock_batches')->restrictOnDelete();
            $table->decimal('quantity_requested', 18, 3);
            $table->decimal('quantity_dispatched', 18, 3)->default(0);
            $table->decimal('quantity_received', 18, 3)->default(0);
            $table->foreignId('discrepancy_reason_id')->nullable()->constrained('reason_codes')->nullOnDelete();
            $table->string('discrepancy_note')->nullable();
        });

        Schema::create('stock_counts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->string('number', 30);
            $table->string('scope', 20); // full, cycle, location, category, product
            $table->jsonb('scope_filter')->nullable();
            $table->boolean('is_blind')->default(true);
            $table->boolean('requires_second_count')->default(false);
            // draft, in_progress, submitted, reviewed, approved, posted, cancelled
            $table->string('status', 20)->default('draft');
            $table->timestampTz('snapshot_at')->nullable();
            $table->unsignedBigInteger('movement_watermark')->nullable(); // last ledger id included in the snapshot
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('posted_at')->nullable();
            $table->text('note')->nullable();
            $table->timestampsTz();
            $table->unique(['company_id', 'number']);
        });

        Schema::create('stock_count_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_count_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->foreignId('variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->foreignId('batch_id')->nullable()->constrained('stock_batches')->restrictOnDelete();
            $table->decimal('expected_quantity', 18, 3); // snapshot at start
            $table->decimal('counted_quantity', 18, 3)->nullable();
            $table->foreignId('counted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('counted_at')->nullable();
            $table->decimal('second_count_quantity', 18, 3)->nullable();
            $table->foreignId('second_counted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('movement_since_snapshot', 18, 3)->nullable();
            $table->decimal('variance_quantity', 18, 3)->nullable();
            $table->decimal('unit_cost', 18, 6)->nullable();
            $table->decimal('variance_value', 16, 2)->nullable();
            $table->foreignId('reason_code_id')->nullable()->constrained()->nullOnDelete();
            $table->string('note')->nullable();
            $table->index(['stock_count_id', 'variant_id']);
        });

        Schema::create('repack_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->string('number', 30);
            $table->foreignId('source_variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->foreignId('source_location_id')->constrained('locations')->restrictOnDelete();
            $table->decimal('source_quantity', 18, 3);
            $table->foreignId('target_variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->foreignId('target_location_id')->constrained('locations')->restrictOnDelete();
            $table->decimal('target_quantity', 18, 3);
            $table->foreignId('packaging_variant_id')->nullable()->constrained('product_variants')->restrictOnDelete();
            $table->decimal('packaging_quantity', 18, 3)->default(0);
            $table->string('status', 20)->default('draft'); // draft, posted
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('posted_at')->nullable();
            $table->timestampsTz();
            $table->unique(['company_id', 'number']);
        });

        Schema::create('replenishment_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->foreignId('variant_id')->constrained('product_variants')->cascadeOnDelete();
            $table->decimal('min_quantity', 18, 3)->default(0);
            $table->decimal('max_quantity', 18, 3)->nullable();
            $table->decimal('reorder_point', 18, 3)->nullable(); // null = computed from demand
            $table->decimal('safety_stock', 18, 3)->default(0);
            $table->foreignId('preferred_supplier_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->unsignedSmallInteger('lead_time_days')->nullable();
            $table->decimal('order_multiple', 18, 3)->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->unique(['store_id', 'variant_id']);
        });
    }

    public function down(): void
    {
        foreach (['replenishment_policies', 'repack_orders', 'stock_count_lines', 'stock_counts', 'stock_transfer_lines', 'stock_transfers', 'stock_adjustment_lines', 'stock_adjustments', 'variant_costs', 'cost_layer_consumptions', 'cost_layers'] as $table) {
            Schema::dropIfExists($table);
        }
        DB::statement('DROP TRIGGER IF EXISTS stock_movements_append_only ON stock_movements');
        Schema::dropIfExists('stock_movements');
        DB::statement('DROP FUNCTION IF EXISTS forbid_ledger_mutation()');
        foreach (['stock_reservations', 'stock_levels', 'stock_batches', 'locations'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
