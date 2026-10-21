<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Batch D (people).
 *  - attendance_breaks: meal / prayer / other breaks, with who entered each one.
 *  - attendance_records: break totals per day.
 *  - user_permission_overrides: grant or revoke a single permission for one user (optionally for one store).
 *  - employee_details: the employer's own insights, written at or after leaving.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_breaks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('store_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->foreignId('attendance_record_id')->nullable()->constrained()->nullOnDelete();
            $table->date('work_date');
            $table->string('type', 20)->default('meal'); // meal, prayer, other
            $table->timestampTz('started_at');
            $table->timestampTz('ended_at')->nullable();
            $table->unsignedSmallInteger('minutes')->default(0);
            $table->boolean('is_unpaid')->default(false);
            $table->foreignId('entered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('entered_by_role', 20)->default('employee'); // employee (their own button) or manager (typed in for them)
            $table->foreignId('ended_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note')->nullable();
            $table->timestampsTz();
            $table->index(['company_id', 'work_date']);
            $table->index(['contact_id', 'work_date']);
        });

        Schema::table('attendance_records', function (Blueprint $table) {
            $table->unsignedSmallInteger('break_minutes')->default(0);
            $table->unsignedSmallInteger('unpaid_break_minutes')->default(0);
            $table->unsignedSmallInteger('break_over_minutes')->default(0);
        });

        Schema::create('user_permission_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('permission', 80);
            $table->string('effect', 10); // grant, revoke
            $table->foreignId('store_id')->nullable()->constrained()->cascadeOnDelete(); // null = every store
            $table->string('reason')->nullable();
            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->index(['user_id']);
        });
        DB::statement('CREATE UNIQUE INDEX user_permission_overrides_unique ON user_permission_overrides (user_id, permission, COALESCE(store_id, 0))');

        Schema::table('employee_details', function (Blueprint $table) {
            $table->text('employer_insights')->nullable();
            $table->foreignId('insights_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('insights_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('employee_details', function (Blueprint $table) {
            $table->dropConstrainedForeignId('insights_by');
            $table->dropColumn(['employer_insights', 'insights_at']);
        });
        Schema::dropIfExists('user_permission_overrides');
        Schema::table('attendance_records', function (Blueprint $table) {
            $table->dropColumn(['break_minutes', 'unpaid_break_minutes', 'break_over_minutes']);
        });
        Schema::dropIfExists('attendance_breaks');
    }
};
