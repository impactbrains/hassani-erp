<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per employee per day of the week (7 rows/employee). Covers
 * both "Timing" and "Usual Work Location" from the Work tab in a single
 * table, since both are naturally "one value per day of the week" and
 * always edited together on the same screen.
 *
 * `day` stores 0=Monday..6=Sunday (ISO-8601 weekday order, matching how
 * you listed them) rather than relying on Carbon's 0=Sunday default —
 * explicit is safer than assuming every reader remembers which
 * convention this column uses.
 *
 * `is_working_day` lets a day be toggled off entirely (e.g. employee
 * doesn't work Sundays) without needing null-juggling on the time
 * columns to mean "day off".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_work_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('day'); // 0=Monday ... 6=Sunday
            $table->boolean('is_working_day')->default(true);
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->string('work_location')->nullable();
            $table->timestamps();

            $table->unique(['contact_id', 'day']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_work_schedules');
    }
};