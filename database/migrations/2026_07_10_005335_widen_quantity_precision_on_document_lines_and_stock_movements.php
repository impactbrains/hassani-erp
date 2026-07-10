<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_lines', function (Blueprint $table) {
            $table->decimal('quantity', 15, 6)->change();
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->decimal('quantity_change', 15, 6)->change();
        });

        Schema::table('product_variants', function (Blueprint $table) {
            $table->decimal('pack_size', 15, 6)->change();
            $table->decimal('low_stock_threshold', 15, 6)->change();
        });
    }

    public function down(): void
    {
        Schema::table('document_lines', function (Blueprint $table) {
            $table->decimal('quantity', 12, 3)->change();
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->decimal('quantity_change', 12, 3)->change();
        });

        Schema::table('product_variants', function (Blueprint $table) {
            $table->decimal('pack_size', 12, 3)->change();
            $table->decimal('low_stock_threshold', 12, 3)->change();
        });
    }
};