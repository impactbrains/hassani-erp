<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Items sold at the POS that were not in the product list yet ("unlisted
 * items"). Each sale is recorded here so the team can see what to add to
 * the catalogue and how often it sells.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('is_open_item')->default(false)->after('is_active'); // the single "unlisted item" product used at the POS
        });

        Schema::create('pending_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('name_key'); // lower-case, single-spaced name used to group repeat sales
            $table->string('barcode', 64)->nullable();
            $table->string('status', 20)->default('pending'); // pending, created, dismissed
            $table->unsignedInteger('times_sold')->default(0);
            $table->decimal('quantity_sold', 18, 3)->default(0);
            $table->decimal('last_price', 18, 2)->nullable();
            $table->decimal('total_sales', 18, 2)->default(0);
            $table->timestampTz('first_sold_at')->nullable();
            $table->timestampTz('last_sold_at')->nullable();
            $table->foreignId('last_store_id')->nullable()->constrained('stores')->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampsTz();
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'name_key']);
            $table->index(['company_id', 'barcode']);
        });

        Schema::create('pending_product_sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pending_product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sales_invoice_line_id')->constrained()->restrictOnDelete();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('quantity', 18, 3);
            $table->decimal('unit_price', 18, 2);
            $table->timestampTz('sold_at');
            $table->index(['pending_product_id', 'sold_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pending_product_sales');
        Schema::dropIfExists('pending_products');
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn('is_open_item'));
    }
};
