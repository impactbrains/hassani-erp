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
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->enum('type', ['pos_order', 'invoice', 'sales_order', 'credit_note']);
            $table->string('number')->unique(); // generated per-type via sequences
            $table->foreignId('contact_id')
                ->nullable() // nullable for walk-in POS sales
                ->constrained('contacts')
                ->nullOnDelete();
            $table->foreignId('location_id')
                ->constrained('locations')
                ->restrictOnDelete();
            $table->enum('status', ['draft', 'confirmed', 'paid', 'cancelled', 'refunded'])
                ->default('draft');
            $table->foreignId('source_document_id')
                ->nullable() // traceability chain, self-referencing
                ->constrained('documents')
                ->nullOnDelete();
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('tax_total', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->decimal('amount_paid', 12, 2)->default(0);
            $table->string('currency', 3)->default('PKR');
            $table->text('notes')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
