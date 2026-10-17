<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sales and POS: payment methods, registers and cash shifts, held carts,
 * sales orders (wholesale / phone / e-commerce), invoices (a POS receipt is
 * an invoice of kind "pos"), credit notes, payments with allocations,
 * deliveries with rider cash handovers, and sales targets.
 *
 * Posted invoices and credit notes are never edited: corrections are new
 * credit notes that reference the original.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('code', 30);
            $table->string('name');
            $table->string('name_ur')->nullable();
            $table->string('type', 20); // cash, card, wallet, bank
            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete();
            $table->boolean('requires_reference')->default(false);
            $table->boolean('requires_verification')->default(false);
            $table->jsonb('terminal_labels')->nullable();
            $table->boolean('available_in_pos')->default(true);
            $table->boolean('available_online')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->unique(['company_id', 'code']);
        });

        Schema::create('registers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->string('code', 20);
            $table->string('name');
            $table->foreignId('location_id')->constrained()->restrictOnDelete(); // sells from here
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->unique(['store_id', 'code']);
        });

        Schema::create('shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->foreignId('register_id')->constrained()->restrictOnDelete();
            $table->string('number', 30);
            $table->string('status', 20)->default('open'); // open, closed
            $table->foreignId('opened_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('opened_at');
            $table->decimal('opening_float', 16, 2)->default(0);
            $table->jsonb('opening_count')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('closed_at')->nullable();
            $table->jsonb('closing_count')->nullable();
            $table->decimal('counted_cash', 16, 2)->nullable();
            $table->decimal('expected_cash', 16, 2)->nullable();
            $table->decimal('variance', 16, 2)->nullable();
            $table->string('variance_status', 20)->default('none'); // none, pending_review, approved
            $table->foreignId('variance_reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('variance_note')->nullable();
            $table->unsignedInteger('z_number')->nullable();
            $table->jsonb('z_snapshot')->nullable();
            $table->date('business_date');
            $table->timestampsTz();
            $table->unique(['company_id', 'number']);
            $table->index(['store_id', 'business_date']);
        });
        DB::statement("CREATE UNIQUE INDEX shifts_one_open_per_register ON shifts (register_id) WHERE status = 'open'");

        Schema::create('cash_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->foreignId('shift_id')->constrained()->restrictOnDelete();
            $table->string('number', 30);
            $table->string('type', 20); // pay_in, drop, payout, no_sale
            $table->decimal('amount', 16, 2)->default(0);
            $table->foreignId('reason_code_id')->nullable()->constrained()->nullOnDelete();
            $table->string('recipient')->nullable();
            $table->string('note')->nullable();
            $table->foreignId('attachment_media_id')->nullable()->constrained('media')->nullOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('occurred_at');
            $table->date('business_date');
            $table->timestampsTz();
            $table->unique(['company_id', 'number']);
        });
        DB::statement('ALTER TABLE cash_movements ADD CONSTRAINT cash_movements_amount_non_negative CHECK (amount >= 0)');

        Schema::create('held_carts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->foreignId('register_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->string('label')->nullable();
            $table->jsonb('cart');
            $table->decimal('estimated_total', 16, 2)->default(0);
            $table->string('status', 20)->default('held'); // held, resumed, discarded
            $table->foreignId('claimed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('claimed_at')->nullable();
            $table->timestampTz('expires_at');
            $table->timestampsTz();
            $table->index(['store_id', 'status']);
        });

        Schema::create('sales_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->string('number', 30);
            $table->string('channel', 20)->default('counter'); // counter, phone, whatsapp, field_agent, ecommerce, api
            $table->foreignId('customer_id')->constrained('contacts')->restrictOnDelete();
            $table->foreignId('price_book_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('sales_agent_id')->nullable()->constrained('contacts')->nullOnDelete();
            // draft, confirmed, picking, packed, dispatched, delivered, completed, cancelled
            $table->string('status', 20)->default('draft');
            $table->string('fulfillment_type', 20)->default('pickup'); // pickup, delivery
            $table->string('delivery_address')->nullable();
            $table->string('delivery_area')->nullable();
            $table->string('delivery_phone', 30)->nullable();
            $table->timestampTz('requested_delivery_at')->nullable();
            $table->string('payment_preference', 30)->nullable(); // cod, khata, wallet code
            $table->decimal('subtotal', 16, 2)->default(0);
            $table->decimal('discount_total', 16, 2)->default(0);
            $table->decimal('tax_total', 16, 2)->default(0);
            $table->decimal('delivery_charge', 16, 2)->default(0);
            $table->decimal('total', 16, 2)->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('cancel_reason_id')->nullable()->constrained('reason_codes')->nullOnDelete();
            $table->timestampTz('confirmed_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->string('idempotency_key', 100)->nullable();
            $table->foreignId('api_client_id')->nullable()->constrained()->nullOnDelete();
            $table->string('external_reference')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('business_date');
            $table->timestampsTz();
            $table->unique(['company_id', 'number']);
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'customer_id']);
        });
        DB::statement('CREATE UNIQUE INDEX sales_orders_idempotency ON sales_orders (company_id, idempotency_key) WHERE idempotency_key IS NOT NULL');

        Schema::create('sales_order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->foreignId('variant_unit_id')->constrained('variant_units')->restrictOnDelete();
            $table->string('description');
            $table->decimal('quantity', 18, 3);
            $table->decimal('unit_factor', 18, 6);
            $table->decimal('base_quantity', 18, 3);
            $table->decimal('unit_price', 16, 2);
            $table->decimal('discount_amount', 16, 2)->default(0);
            $table->decimal('tax_rate', 7, 4)->default(0);
            $table->boolean('tax_inclusive')->default(true);
            $table->decimal('tax_amount', 16, 2)->default(0);
            $table->decimal('line_total', 16, 2);
            $table->decimal('base_quantity_invoiced', 18, 3)->default(0);
            $table->decimal('base_quantity_cancelled', 18, 3)->default(0);
        });

        Schema::create('sales_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->string('number', 30);
            $table->string('kind', 10)->default('pos'); // pos, invoice
            $table->string('status', 20)->default('posted'); // draft, posted
            $table->string('payment_status', 20)->default('unpaid'); // unpaid, partial, paid
            $table->string('channel', 20)->default('counter');
            $table->foreignId('customer_id')->nullable()->constrained('contacts')->restrictOnDelete();
            $table->string('customer_name')->nullable(); // snapshot
            $table->string('customer_phone', 30)->nullable(); // snapshot
            $table->foreignId('price_book_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('sales_order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('register_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('shift_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('cashier_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('sales_agent_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->decimal('subtotal', 16, 2)->default(0); // gross line amounts before discounts
            $table->decimal('discount_total', 16, 2)->default(0);
            $table->decimal('tax_total', 16, 2)->default(0);
            $table->decimal('delivery_charge', 16, 2)->default(0);
            $table->decimal('rounding_adjustment', 16, 2)->default(0);
            $table->decimal('total', 16, 2)->default(0);
            $table->decimal('amount_paid', 16, 2)->default(0);
            $table->decimal('amount_credited', 16, 2)->default(0);
            $table->decimal('credit_extended', 16, 2)->default(0); // put on Khata at the time of sale
            $table->decimal('cost_total', 16, 2)->default(0);
            $table->boolean('cost_complete')->default(true); // false when any line had no known cost
            $table->date('due_date')->nullable();
            $table->date('business_date');
            $table->timestampTz('posted_at')->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('idempotency_key', 100)->nullable();
            $table->text('notes')->nullable();
            $table->timestampsTz();
            $table->unique(['company_id', 'number']);
            $table->index(['company_id', 'business_date']);
            $table->index(['company_id', 'customer_id', 'payment_status']);
            $table->index(['shift_id']);
        });
        DB::statement('CREATE UNIQUE INDEX sales_invoices_idempotency ON sales_invoices (company_id, idempotency_key) WHERE idempotency_key IS NOT NULL');

        Schema::create('sales_invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_invoice_id')->constrained()->restrictOnDelete();
            $table->foreignId('sales_order_line_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->foreignId('variant_unit_id')->constrained('variant_units')->restrictOnDelete();
            $table->string('description');
            $table->string('description_ur')->nullable();
            $table->decimal('quantity', 18, 3);
            $table->decimal('unit_factor', 18, 6);
            $table->decimal('base_quantity', 18, 3);
            $table->decimal('list_price', 16, 2); // price-book price
            $table->decimal('unit_price', 16, 2); // charged price (after override)
            $table->decimal('mrp', 16, 2)->nullable();
            $table->boolean('price_overridden')->default(false);
            $table->decimal('line_discount', 16, 2)->default(0);
            $table->decimal('cart_discount_share', 16, 2)->default(0);
            $table->foreignId('discount_reason_id')->nullable()->constrained('reason_codes')->nullOnDelete();
            $table->decimal('tax_rate', 7, 4)->default(0);
            $table->boolean('tax_inclusive')->default(true);
            $table->decimal('tax_amount', 16, 2)->default(0);
            $table->decimal('revenue_amount', 16, 2); // ex tax, after discounts
            $table->decimal('line_total', 16, 2); // what the customer pays for this line
            $table->decimal('cost_amount', 16, 2)->nullable(); // COGS; null = cost unknown
            $table->decimal('base_quantity_returned', 18, 3)->default(0);
            $table->foreignId('approval_id')->nullable()->constrained()->nullOnDelete();
            $table->index(['sales_invoice_id']);
            $table->index(['variant_id']);
        });

        Schema::create('credit_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->string('number', 30);
            $table->foreignId('sales_invoice_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('contacts')->restrictOnDelete();
            $table->foreignId('reason_code_id')->nullable()->constrained()->nullOnDelete();
            $table->string('settlement', 20); // refund, khata_credit
            $table->decimal('subtotal', 16, 2)->default(0);
            $table->decimal('tax_total', 16, 2)->default(0);
            $table->decimal('total', 16, 2)->default(0);
            $table->decimal('cost_total', 16, 2)->default(0);
            $table->foreignId('shift_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('register_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('approval_id')->nullable()->constrained()->nullOnDelete();
            $table->date('business_date');
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('posted_at');
            $table->string('idempotency_key', 100)->nullable();
            $table->text('notes')->nullable();
            $table->timestampsTz();
            $table->unique(['company_id', 'number']);
            $table->index(['company_id', 'business_date']);
        });
        DB::statement('CREATE UNIQUE INDEX credit_notes_idempotency ON credit_notes (company_id, idempotency_key) WHERE idempotency_key IS NOT NULL');

        Schema::create('credit_note_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('credit_note_id')->constrained()->restrictOnDelete();
            $table->foreignId('sales_invoice_line_id')->constrained()->restrictOnDelete();
            $table->foreignId('variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->decimal('quantity', 18, 3);
            $table->decimal('base_quantity', 18, 3);
            $table->decimal('tax_amount', 16, 2)->default(0);
            $table->decimal('revenue_amount', 16, 2);
            $table->decimal('line_total', 16, 2);
            $table->decimal('cost_amount', 16, 2)->nullable();
            $table->string('disposition', 20); // restock, damaged, quarantine, none
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->string('number', 30);
            $table->string('direction', 3); // in, out
            // sale, collection, refund, supplier_payment, delivery_collection
            $table->string('kind', 30);
            $table->foreignId('contact_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('payment_method_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 16, 2);
            $table->decimal('tendered_amount', 16, 2)->nullable();
            $table->decimal('change_amount', 16, 2)->default(0);
            $table->string('reference')->nullable();
            $table->string('terminal_label')->nullable();
            // not_required, pending, verified, rejected
            $table->string('verification_status', 20)->default('not_required');
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('verified_at')->nullable();
            $table->string('custody', 20)->default('drawer'); // drawer, rider, bank, wallet
            $table->foreignId('rider_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->unsignedBigInteger('rider_handover_id')->nullable();
            $table->foreignId('shift_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('register_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('received_at');
            $table->date('business_date');
            $table->string('idempotency_key', 100)->nullable();
            $table->text('notes')->nullable();
            $table->timestampsTz();
            $table->unique(['company_id', 'number']);
            $table->index(['company_id', 'business_date']);
            $table->index(['shift_id']);
            $table->index(['contact_id']);
        });
        DB::statement('CREATE UNIQUE INDEX payments_idempotency ON payments (company_id, idempotency_key) WHERE idempotency_key IS NOT NULL');
        DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_amount_positive CHECK (amount > 0)');

        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_id')->constrained()->restrictOnDelete();
            $table->string('allocatable_type');
            $table->unsignedBigInteger('allocatable_id');
            $table->decimal('amount', 16, 2);
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['allocatable_type', 'allocatable_id']);
            $table->index('payment_id');
        });
        DB::statement('ALTER TABLE payment_allocations ADD CONSTRAINT payment_allocations_positive CHECK (amount > 0)');

        Schema::create('deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->string('number', 30);
            $table->foreignId('sales_order_id')->constrained()->restrictOnDelete();
            $table->foreignId('sales_invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('rider_id')->nullable()->constrained('contacts')->nullOnDelete();
            // pending, assigned, out_for_delivery, delivered, failed, returned, cancelled
            $table->string('status', 20)->default('pending');
            $table->decimal('cod_amount', 16, 2)->default(0);
            $table->decimal('collected_amount', 16, 2)->default(0);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('failure_reason')->nullable();
            $table->string('proof_note')->nullable();
            $table->timestampTz('assigned_at')->nullable();
            $table->timestampTz('dispatched_at')->nullable();
            $table->timestampTz('delivered_at')->nullable();
            $table->timestampsTz();
            $table->unique(['company_id', 'number']);
            $table->index(['company_id', 'rider_id', 'status']);
        });

        Schema::create('delivery_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivery_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20);
            $table->string('note')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestampTz('created_at')->useCurrent();
        });

        Schema::create('rider_handovers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->string('number', 30);
            $table->foreignId('rider_id')->constrained('contacts')->restrictOnDelete();
            $table->foreignId('shift_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('expected_amount', 16, 2);
            $table->decimal('received_amount', 16, 2);
            $table->decimal('variance', 16, 2)->default(0);
            $table->string('note')->nullable();
            $table->foreignId('received_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('received_at');
            $table->date('business_date');
            $table->timestampsTz();
            $table->unique(['company_id', 'number']);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->foreign('rider_handover_id')->references('id')->on('rider_handovers')->nullOnDelete();
        });

        Schema::create('sales_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('store_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('sales_agent_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->string('metric', 30); // net_sales, collections, new_customers, orders
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('target_value', 16, 2);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_targets');
        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign(['rider_handover_id']);
        });
        foreach (['rider_handovers', 'delivery_events', 'deliveries', 'payment_allocations', 'payments', 'credit_note_lines', 'credit_notes', 'sales_invoice_lines', 'sales_invoices', 'sales_order_lines', 'sales_orders', 'held_carts', 'cash_movements', 'shifts', 'registers', 'payment_methods'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
