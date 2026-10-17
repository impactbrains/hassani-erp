<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Product catalog: units of measure and pack conversions, categories,
 * brands, effective-dated tax rates, products and SKUs (variants),
 * barcodes, effective-dated price books, bundles and reason codes.
 *
 * Stock is always held in a variant's base unit. variant_units.factor says
 * how many base units one pack contains (a "ladi" of 12 sachets has
 * factor 12 when the base unit is "sachet").
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('code', 20);
            $table->string('name');
            $table->string('name_ur')->nullable();
            $table->string('kind', 20)->default('count'); // count, weight, volume, length
            $table->boolean('allows_fraction')->default(false);
            $table->unsignedTinyInteger('decimals')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->unique(['company_id', 'code']);
        });

        Schema::create('product_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('product_categories')->restrictOnDelete();
            $table->string('name');
            $table->string('name_ur')->nullable();
            $table->string('slug');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->unique(['company_id', 'slug']);
        });

        Schema::create('brands', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('name_ur')->nullable();
            $table->string('manufacturer')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->unique(['company_id', 'name']);
        });

        Schema::create('tax_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('code', 30);
            $table->string('name');
            $table->boolean('is_default')->default(false);
            $table->timestampsTz();
            $table->unique(['company_id', 'code']);
        });

        Schema::create('tax_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('tax_category_id')->constrained()->restrictOnDelete();
            $table->decimal('rate', 7, 4); // percent, e.g. 18.0000
            $table->boolean('is_inclusive')->default(true); // shelf prices already include tax
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->index(['tax_category_id', 'effective_from']);
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('name_ur')->nullable();
            $table->foreignId('category_id')->nullable()->constrained('product_categories')->nullOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained()->nullOnDelete();
            $table->string('manufacturer')->nullable();
            $table->text('description')->nullable();
            $table->text('description_ur')->nullable();
            $table->string('type', 20)->default('stocked'); // stocked, non_inventory, service, bundle
            $table->foreignId('tax_category_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('track_batches')->default(false);
            $table->boolean('track_expiry')->default(false);
            $table->unsignedInteger('shelf_life_days')->nullable();
            $table->foreignId('image_media_id')->nullable()->constrained('media')->nullOnDelete();
            $table->boolean('is_online')->default(false); // visible to the e-commerce API
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->index(['company_id', 'is_active']);
            $table->index(['company_id', 'category_id']);
        });
        DB::statement('CREATE INDEX products_name_trgm ON products USING gin (lower(name) gin_trgm_ops)');

        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('sku', 60);
            $table->string('name');
            $table->string('name_ur')->nullable();
            $table->foreignId('base_unit_id')->constrained('units')->restrictOnDelete();
            $table->boolean('is_loose')->default(false); // sold by weight/volume at the counter
            $table->decimal('mrp', 16, 2)->nullable(); // printed maximum retail price
            $table->jsonb('attributes')->nullable(); // size, flavour, pack size ...
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->unique(['company_id', 'sku']);
            $table->index(['product_id']);
        });
        DB::statement('CREATE INDEX product_variants_name_trgm ON product_variants USING gin (lower(name) gin_trgm_ops)');
        DB::statement('CREATE INDEX product_variants_sku_trgm ON product_variants USING gin (lower(sku) gin_trgm_ops)');

        Schema::create('variant_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('variant_id')->constrained('product_variants')->cascadeOnDelete();
            $table->foreignId('unit_id')->constrained()->restrictOnDelete();
            $table->decimal('factor', 18, 6); // base units contained in one of this unit
            $table->boolean('is_base')->default(false);
            $table->boolean('can_sell')->default(true);
            $table->boolean('can_purchase')->default(true);
            $table->boolean('is_default_sale')->default(false);
            $table->boolean('is_default_purchase')->default(false);
            $table->timestampsTz();
            $table->unique(['variant_id', 'unit_id']);
        });
        DB::statement('ALTER TABLE variant_units ADD CONSTRAINT variant_units_factor_positive CHECK (factor > 0)');

        Schema::create('barcodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('variant_id')->constrained('product_variants')->cascadeOnDelete();
            $table->foreignId('variant_unit_id')->nullable()->constrained('variant_units')->cascadeOnDelete();
            $table->string('code', 64);
            $table->string('symbology', 20)->default('EAN13'); // EAN13, EAN8, UPCA, CODE128, QR, INTERNAL
            $table->boolean('is_primary')->default(false);
            $table->timestampsTz();
            $table->unique(['company_id', 'code']);
        });

        Schema::create('price_books', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('code', 30);
            $table->string('name');
            $table->string('name_ur')->nullable();
            $table->string('kind', 20)->default('retail'); // retail, wholesale, custom
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->unique(['company_id', 'code']);
        });

        Schema::create('prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('price_book_id')->constrained()->restrictOnDelete();
            $table->foreignId('variant_id')->constrained('product_variants')->cascadeOnDelete();
            $table->foreignId('variant_unit_id')->constrained('variant_units')->cascadeOnDelete();
            $table->decimal('price', 16, 2);
            $table->decimal('min_quantity', 18, 3)->default(1); // wholesale tiers
            $table->timestampTz('effective_from');
            $table->timestampTz('effective_to')->nullable();
            $table->string('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->index(['price_book_id', 'variant_id', 'effective_from']);
        });
        DB::statement('ALTER TABLE prices ADD CONSTRAINT prices_non_negative CHECK (price >= 0)');

        Schema::create('bundle_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('bundle_variant_id')->constrained('product_variants')->cascadeOnDelete();
            $table->foreignId('component_variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->decimal('quantity', 18, 3); // in the component's base unit
            $table->timestampsTz();
            $table->unique(['bundle_variant_id', 'component_variant_id']);
        });

        Schema::create('reason_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('type', 30);
            $table->string('code', 40);
            $table->string('label');
            $table->string('label_ur')->nullable();
            $table->boolean('requires_note')->default(false);
            $table->unsignedBigInteger('account_id')->nullable(); // FK added in accounting migration
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->unique(['company_id', 'type', 'code']);
        });
    }

    public function down(): void
    {
        foreach (['reason_codes', 'bundle_components', 'prices', 'price_books', 'barcodes', 'variant_units', 'product_variants', 'products', 'tax_rates', 'tax_categories', 'brands', 'product_categories', 'units'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
