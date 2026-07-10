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
        Schema::create('document_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')
                ->constrained('documents')
                ->cascadeOnDelete();
            $table->foreignId('variant_id')
                ->nullable() // nullable: lets a line exist with no real variant behind it
                ->constrained('product_variants')
                ->nullOnDelete();
            $table->string('description'); // snapshot, not a live join
            $table->decimal('quantity', 12, 3);
            $table->decimal('unit_price', 12, 2); // snapshot
            $table->foreignId('tax_id')
                ->nullable()
                ->constrained('taxes')
                ->nullOnDelete();
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('discount_percent', 5, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('line_total', 12, 2);
            $table->boolean('is_custom')->default(false); // flags off-list / loose-portion lines for reporting
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('document_lines');
    }
};
