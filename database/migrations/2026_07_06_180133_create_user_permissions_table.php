<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('app'); // pos, inventory, invoicing, sales, accounting, website, contacts, barcode, todo, chat
            $table->string('section')->default('*'); // '*' = whole-app permission; later: 'products', 'reports', etc.
            $table->enum('access_level', ['none', 'user', 'admin'])->default('none');
            $table->timestamps();

            $table->unique(['user_id', 'app', 'section']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_permissions');
    }
};