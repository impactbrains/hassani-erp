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
        Schema::create('sequences', function (Blueprint $table) {
            $table->id();
            $table->string('document_type')->unique(); // e.g. invoice, pos_order
            $table->string('prefix'); // e.g. "INV-2026-"
            $table->unsignedInteger('next_number')->default(1);
            $table->unsignedTinyInteger('padding')->default(4); // e.g. 0001
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sequences');
    }
};
