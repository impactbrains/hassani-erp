<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Matches the screenshot exactly: "You can add skills from our library
 * to the employee profile. If skills are missing, they can be created
 * by an HR officer." — so `skills` is a shared library (reusable across
 * every employee, same spirit as your Products categories/units/taxes),
 * and `employee_skill` is the many-to-many pivot assigning specific
 * skills to a specific employee.
 *
 * `level` is optional per-assignment proficiency (e.g. "Advanced") —
 * not in your original spec, but skills libraries almost always want
 * this eventually and it costs nothing to have nullable now. Ignore it
 * on the frontend if you don't want it yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('skills', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });

        Schema::create('employee_skill', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->foreignId('skill_id')->constrained()->cascadeOnDelete();
            $table->string('level')->nullable();
            $table->timestamps();

            $table->unique(['contact_id', 'skill_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_skill');
        Schema::dropIfExists('skills');
    }
};