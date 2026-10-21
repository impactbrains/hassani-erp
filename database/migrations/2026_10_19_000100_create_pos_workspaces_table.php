<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One saved POS screen per person per store: the open cart tabs, so a refresh (or a crash)
 * returns to the same terminal, shift and carts. Carts are never "sales" until paid.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_workspaces', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('register_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('shift_id')->nullable()->constrained()->nullOnDelete();
            $table->jsonb('state');
            $table->unsignedInteger('version')->default(1);
            $table->timestampsTz();
            $table->unique(['store_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_workspaces');
    }
};
