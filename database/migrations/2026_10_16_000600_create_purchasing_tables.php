<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Purchasing: supplier catalog and price lists, purchase orders (a
 * "submitted" PO is a requisition awaiting approval), goods receipts with
 * QC hold and landed costs, supplier bills and supplier returns.
 *
 * A PO is not proof of receipt; a receipt is not proof of a bill; a bill is
 * not proof of payment. Each step is its own document.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained('contacts')->cascadeOnDelete();
            $table->foreignId('variant_id')->constrained('product_variants')->cascadeOnDelete();
            $table->foreignId('variant_unit_id')->constrained('variant_units')->cascadeOnDelete();
            $table->string('supplier_sku', 60)->nullable();
            $table->decimal('min_order_quantity', 18, 3)->default(1);
            $table->unsignedSmallInteger('lead_time_days')->nullable();
            $table->boolean('is_preferred')->default(false);
            $table->timestampsTz();
            $table->unique(['supplier_id', 'variant_id', 'variant_unit_id']);
        });

        Schema::create('supplier_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained('contacts')->cascadeOnDelete();
            $table->foreignId('variant_id')->constrained('product_variants')->cascadeOnDelete();
            $table->foreignId('variant_unit_id')->constrained('variant_units')->cascadeOnDelete();
            $table->decimal('price', 16, 2);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->index(['supplier_id', 'variant_id', 'effective_from']);
        });

        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->string('number', 30);
            $table->foreignId('supplier_id')->constrained('contacts')->restrictOnDelete();
            // draft, submitted, approved, sent, partially_received, received, closed, cancelled
            $table->string('status', 20)->default('draft');
            $table->string('source', 20)->default('manual'); // manual, replenishment
            $table->date('order_date');
            $table->date('expected_date')->nullable();
            $table->decimal('subtotal', 16, 2)->default(0);
            $table->decimal('tax_total', 16, 2)->default(0);
            $table->decimal('total', 16, 2)->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->timestampsTz();
            $table->unique(['company_id', 'number']);
            $table->index(['company_id', 'status']);
        });

        Schema::create('purchase_order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->foreignId('variant_unit_id')->constrained('variant_units')->restrictOnDelete();
            $table->decimal('quantity', 18, 3);
            $table->decimal('unit_factor', 18, 6);
            $table->decimal('base_quantity', 18, 3);
            $table->decimal('unit_price', 16, 2);
            $table->decimal('tax_rate', 7, 4)->default(0);
            $table->decimal('line_total', 16, 2);
            $table->decimal('base_quantity_received', 18, 3)->default(0);
            $table->decimal('base_quantity_cancelled', 18, 3)->default(0);
        });

        Schema::create('goods_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->string('number', 30);
            $table->foreignId('supplier_id')->constrained('contacts')->restrictOnDelete();
            $table->foreignId('purchase_order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->string('status', 20)->default('draft'); // draft, posted
            $table->string('supplier_reference')->nullable();
            $table->boolean('is_blind')->default(false);
            $table->decimal('goods_value', 16, 2)->default(0);
            $table->decimal('landed_cost_total', 16, 2)->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('received_at')->nullable();
            $table->date('business_date')->nullable();
            $table->timestampsTz();
            $table->unique(['company_id', 'number']);
        });

        Schema::create('goods_receipt_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('goods_receipt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_order_line_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->foreignId('variant_unit_id')->constrained('variant_units')->restrictOnDelete();
            $table->decimal('unit_factor', 18, 6);
            $table->decimal('quantity_expected', 18, 3)->nullable();
            $table->decimal('quantity_received', 18, 3); // in purchase units, accepted + damaged
            $table->decimal('quantity_damaged', 18, 3)->default(0);
            $table->decimal('base_quantity_accepted', 18, 3)->default(0);
            $table->decimal('unit_price', 16, 2); // per purchase unit, ex landed cost
            $table->decimal('landed_cost_share', 16, 2)->default(0);
            $table->decimal('base_unit_cost', 18, 6)->default(0); // final cost per base unit
            $table->string('batch_no', 60)->nullable();
            $table->date('expiry_date')->nullable();
            $table->foreignId('batch_id')->nullable()->constrained('stock_batches')->nullOnDelete();
            $table->string('quality_status', 20)->default('accepted'); // accepted, quarantined
            $table->decimal('base_quantity_billed', 18, 3)->default(0);
            $table->decimal('base_quantity_returned', 18, 3)->default(0);
            $table->string('discrepancy_note')->nullable();
        });

        Schema::create('landed_costs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('goods_receipt_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20); // freight, duty, handling, insurance, other
            $table->string('description')->nullable();
            $table->decimal('amount', 16, 2);
            $table->string('allocation_method', 20)->default('value'); // value, quantity
        });

        Schema::create('supplier_bills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->string('number', 30);
            $table->foreignId('supplier_id')->constrained('contacts')->restrictOnDelete();
            $table->string('supplier_invoice_no')->nullable();
            $table->date('bill_date');
            $table->date('due_date')->nullable();
            $table->string('status', 20)->default('draft'); // draft, posted
            $table->string('payment_status', 20)->default('unpaid'); // unpaid, partial, paid
            $table->decimal('subtotal', 16, 2)->default(0);
            $table->decimal('tax_total', 16, 2)->default(0);
            $table->decimal('total', 16, 2)->default(0);
            $table->decimal('amount_paid', 16, 2)->default(0);
            $table->decimal('amount_credited', 16, 2)->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('posted_at')->nullable();
            $table->timestampsTz();
            $table->unique(['company_id', 'number']);
            $table->index(['company_id', 'supplier_id', 'payment_status']);
        });

        Schema::create('supplier_bill_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_bill_id')->constrained()->cascadeOnDelete();
            $table->foreignId('goods_receipt_line_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->restrictOnDelete();
            $table->foreignId('account_id')->nullable()->constrained('accounts')->restrictOnDelete(); // expense lines
            $table->string('description');
            $table->decimal('base_quantity', 18, 3)->default(0);
            $table->decimal('quantity', 18, 3);
            $table->decimal('unit_price', 16, 2);
            $table->decimal('tax_amount', 16, 2)->default(0);
            $table->decimal('line_total', 16, 2);
        });

        Schema::create('supplier_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->string('number', 30);
            $table->foreignId('supplier_id')->constrained('contacts')->restrictOnDelete();
            $table->foreignId('goods_receipt_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('reason_code_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 20)->default('draft'); // draft, posted
            $table->decimal('total', 16, 2)->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('posted_at')->nullable();
            $table->date('business_date')->nullable();
            $table->timestampsTz();
            $table->unique(['company_id', 'number']);
        });

        Schema::create('supplier_return_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_return_id')->constrained()->cascadeOnDelete();
            $table->foreignId('goods_receipt_line_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->foreignId('batch_id')->nullable()->constrained('stock_batches')->restrictOnDelete();
            $table->decimal('base_quantity', 18, 3);
            $table->decimal('unit_cost', 18, 6);
            $table->decimal('line_total', 16, 2);
        });
        DB::statement('ALTER TABLE supplier_return_lines ADD CONSTRAINT supplier_return_lines_positive CHECK (base_quantity > 0)');
    }

    public function down(): void
    {
        foreach (['supplier_return_lines', 'supplier_returns', 'supplier_bill_lines', 'supplier_bills', 'landed_costs', 'goods_receipt_lines', 'goods_receipts', 'purchase_order_lines', 'purchase_orders', 'supplier_prices', 'supplier_products'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
