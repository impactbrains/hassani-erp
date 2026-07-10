<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
         Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('variant_id')
                ->constrained('product_variants')
                ->restrictOnDelete();
            $table->foreignId('location_id')
                ->constrained('locations')
                ->restrictOnDelete();
            $table->decimal('quantity_change', 12, 3); // positive or negative, fractional allowed
            $table->enum('type', ['sale', 'purchase', 'adjustment', 'return', 'transfer']);
            $table->string('reference_type')->nullable(); // polymorphic, e.g. "document"
            $table->unsignedBigInteger('reference_id')->nullable(); // polymorphic, e.g. document.id
            $table->string('note')->nullable();
            $table->timestamp('created_at')->useCurrent(); // no updated_at: ledger rows are immutable

            $table->index(['reference_type', 'reference_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
