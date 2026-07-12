<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Simple manual badges, as decided: admin creates a badge (name + icon)
 * once in a shared library, then assigns/revokes it per employee.
 * `icon` stores a Lucide icon name (you're already using
 * lucide-react/lucide-vue elsewhere per your stack) so the frontend can
 * render it directly without an image upload for every badge.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('badges', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('icon')->nullable();
            $table->string('color')->nullable();
            $table->timestamps();
        });

        Schema::create('employee_badge', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->foreignId('badge_id')->constrained()->cascadeOnDelete();
            $table->timestamp('awarded_at')->nullable();
            $table->timestamps();

            $table->unique(['contact_id', 'badge_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_badge');
        Schema::dropIfExists('badges');
    }
};