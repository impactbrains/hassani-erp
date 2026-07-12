<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Repeatable timeline entries for the Resume tab — matches the
 * screenshot's "Experience - Office Design Project LLP" / "Other
 * Experience" blocks with a date range and a description, plus an ADD
 * button and a trash icon to delete a row.
 *
 * `type` distinguishes experience vs. education entries so both can
 * share one table and one timeline-rendering component on the frontend,
 * rather than two near-identical tables.
 *
 * `title` is the bold heading line (e.g. "Office Design Project LLP",
 * or a degree name for education); `subtitle` is the secondary line
 * (e.g. "IT" role, or field of study); `is_current` drives the
 * "Current" label instead of an end date, same as the screenshot shows
 * "02/26/2026 - Current".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_resume_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->enum('type', ['experience', 'education']);
            $table->string('title');
            $table->string('subtitle')->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->boolean('is_current')->default(false);
            $table->text('description')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_resume_items');
    }
};