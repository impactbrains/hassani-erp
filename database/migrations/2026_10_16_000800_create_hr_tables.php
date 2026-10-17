<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HR: employee profiles (an employee is a contact of type "employee"),
 * private documents, lifecycle, assets, training, rosters, attendance,
 * leave, holidays, salary structures, payroll, Peshgi advances, restricted
 * HR cases and performance reviews.
 *
 * Sensitive values (CNIC, bank account, guarantor identity) are encrypted
 * at rest by model casts. Documents live on the private disk and are only
 * served through authorised, audited, expiring downloads.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('name_ur')->nullable();
            $table->timestampsTz();
            $table->unique(['company_id', 'name']);
        });

        Schema::create('job_positions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('name_ur')->nullable();
            $table->timestampsTz();
            $table->unique(['company_id', 'name']);
        });

        Schema::create('employee_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('contact_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('employee_no', 30);
            $table->foreignId('store_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('job_position_id')->nullable()->constrained()->nullOnDelete();
            $table->string('job_title')->nullable();
            $table->string('role_type', 30)->nullable(); // cashier, sales_agent, rider, warehouse, supervisor, manager, admin
            $table->foreignId('manager_contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->string('employment_type', 20)->default('permanent'); // permanent, probation, contract, daily_wage, part_time
            // onboarding, probation, active, suspended, resigned, terminated
            $table->string('status', 20)->default('onboarding');
            $table->date('joining_date')->nullable();
            $table->date('probation_end_date')->nullable();
            $table->date('confirmation_date')->nullable();
            $table->date('exit_date')->nullable();
            $table->string('exit_reason')->nullable();
            $table->text('cnic')->nullable(); // encrypted
            $table->string('cnic_hash', 64)->nullable(); // for duplicate detection only
            $table->date('cnic_expiry')->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('gender', 20)->nullable();
            $table->string('father_name')->nullable();
            $table->string('marital_status', 20)->nullable();
            $table->string('private_address')->nullable();
            $table->string('emergency_contact_name')->nullable();
            $table->string('emergency_contact_phone', 30)->nullable();
            $table->string('emergency_contact_relation', 40)->nullable();
            $table->string('bank_name')->nullable();
            $table->text('bank_account')->nullable(); // encrypted
            $table->string('eobi_no')->nullable();
            $table->string('social_security_no')->nullable();
            $table->string('education_level')->nullable();
            $table->foreignId('photo_media_id')->nullable()->constrained('media')->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestampsTz();
            $table->unique(['company_id', 'employee_no']);
            $table->index(['company_id', 'status']);
        });
        DB::statement('CREATE UNIQUE INDEX employee_details_cnic_unique ON employee_details (company_id, cnic_hash) WHERE cnic_hash IS NOT NULL');

        Schema::create('employee_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            // cnic, contract, police_verification, reference, guarantor, medical, education, other
            $table->string('type', 30);
            $table->string('title');
            $table->foreignId('media_id')->constrained('media')->restrictOnDelete();
            $table->date('expires_on')->nullable();
            $table->string('verification_status', 20)->default('not_checked'); // not_checked, checked, rejected
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
        });

        Schema::create('employee_guarantors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('cnic')->nullable(); // encrypted
            $table->string('phone', 30)->nullable();
            $table->string('relation', 40)->nullable();
            $table->string('address')->nullable();
            $table->string('verification_status', 20)->default('not_checked');
            $table->timestampsTz();
        });

        Schema::create('employee_lifecycle_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            // onboarded, probation_started, confirmed, promoted, transferred, suspended,
            // reinstated, resigned, terminated, clearance_completed
            $table->string('type', 30);
            $table->date('effective_date');
            $table->jsonb('details')->nullable();
            $table->string('note')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->index(['contact_id', 'effective_date']);
        });

        Schema::create('employee_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->string('category', 30); // uniform, footwear, equipment, phone, vehicle
            $table->string('description');
            $table->unsignedInteger('quantity')->default(1);
            $table->decimal('value', 16, 2)->nullable();
            $table->date('issued_on');
            $table->date('returned_on')->nullable();
            $table->string('return_condition')->nullable();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
        });

        Schema::create('employee_resume_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20); // experience, education
            $table->string('title');
            $table->string('subtitle')->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->boolean('is_current')->default(false);
            $table->text('description')->nullable();
            $table->string('reference_name')->nullable();
            $table->string('reference_phone', 30)->nullable();
            $table->string('verification_status', 20)->default('not_checked');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestampsTz();
        });

        Schema::create('skills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->timestampsTz();
            $table->unique(['company_id', 'name']);
        });

        Schema::create('employee_skill', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->foreignId('skill_id')->constrained()->cascadeOnDelete();
            $table->string('level', 20)->nullable();
            $table->timestampsTz();
            $table->unique(['contact_id', 'skill_id']);
        });

        Schema::create('badges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('icon')->nullable();
            $table->string('color')->nullable();
            $table->timestampsTz();
            $table->unique(['company_id', 'name']);
        });

        Schema::create('employee_badge', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->foreignId('badge_id')->constrained()->cascadeOnDelete();
            $table->timestampTz('awarded_at')->nullable();
            $table->timestampsTz();
            $table->unique(['contact_id', 'badge_id']);
        });

        Schema::create('trainings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_mandatory')->default(false);
            $table->timestampsTz();
        });

        Schema::create('employee_trainings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->foreignId('training_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20)->default('assigned'); // assigned, completed
            $table->date('due_on')->nullable();
            $table->date('completed_on')->nullable();
            $table->string('outcome')->nullable();
            $table->timestampsTz();
            $table->unique(['contact_id', 'training_id']);
        });

        Schema::create('work_shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('store_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->time('start_time');
            $table->time('end_time');
            $table->unsignedSmallInteger('break_minutes')->default(0);
            $table->unsignedSmallInteger('grace_minutes')->nullable(); // null = company policy
            $table->string('color', 20)->default('slate');
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });

        Schema::create('roster_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->foreignId('work_shift_id')->constrained()->restrictOnDelete();
            $table->date('work_date');
            $table->string('status', 20)->default('scheduled'); // scheduled, cancelled
            $table->string('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->unique(['contact_id', 'work_date', 'work_shift_id']);
            $table->index(['store_id', 'work_date']);
        });

        Schema::create('shift_swap_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('roster_entry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by_contact_id')->constrained('contacts')->cascadeOnDelete();
            $table->foreignId('covering_contact_id')->constrained('contacts')->cascadeOnDelete();
            $table->string('status', 20)->default('pending'); // pending, approved, rejected
            $table->string('reason')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('attendance_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('store_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->date('work_date');
            $table->foreignId('roster_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->timestampTz('clock_in_at')->nullable();
            $table->timestampTz('clock_out_at')->nullable();
            $table->string('clock_in_source', 20)->nullable(); // web, manual, device, mobile
            $table->string('clock_out_source', 20)->nullable();
            $table->string('device_label')->nullable();
            // present, late, half_day, absent, on_leave, holiday, off
            $table->string('status', 20)->default('present');
            $table->unsignedSmallInteger('late_minutes')->default(0);
            $table->unsignedSmallInteger('early_leave_minutes')->default(0);
            $table->unsignedSmallInteger('worked_minutes')->default(0);
            $table->unsignedSmallInteger('overtime_minutes')->default(0);
            $table->boolean('is_corrected')->default(false);
            $table->string('note')->nullable();
            $table->timestampsTz();
            $table->unique(['contact_id', 'work_date']);
            $table->index(['company_id', 'work_date']);
        });

        Schema::create('attendance_corrections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->foreignId('attendance_record_id')->nullable()->constrained()->nullOnDelete();
            $table->date('work_date');
            $table->timestampTz('requested_clock_in')->nullable();
            $table->timestampTz('requested_clock_out')->nullable();
            $table->string('reason');
            $table->string('status', 20)->default('pending'); // pending, approved, rejected
            $table->jsonb('original_values')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->string('review_note')->nullable();
            $table->timestampsTz();
        });

        Schema::create('leave_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('code', 20);
            $table->string('name');
            $table->string('name_ur')->nullable();
            $table->boolean('is_paid')->default(true);
            $table->decimal('default_days_per_year', 5, 1)->default(0);
            $table->decimal('carry_forward_max', 5, 1)->default(0);
            $table->boolean('requires_document')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->unique(['company_id', 'code']);
        });

        Schema::create('leave_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('year');
            $table->decimal('days', 5, 1);
            $table->string('note')->nullable();
            $table->timestampsTz();
            $table->unique(['contact_id', 'leave_type_id', 'year']);
        });

        Schema::create('leave_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained()->restrictOnDelete();
            $table->date('start_date');
            $table->date('end_date');
            $table->decimal('days', 5, 1);
            $table->boolean('half_day')->default(false);
            $table->string('reason')->nullable();
            $table->string('status', 20)->default('pending'); // pending, approved, rejected, cancelled
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->string('review_note')->nullable();
            $table->timestampsTz();
            $table->index(['company_id', 'status']);
            $table->index(['contact_id', 'start_date']);
        });

        Schema::create('holidays', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('store_id')->nullable()->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('name');
            $table->string('name_ur')->nullable();
            $table->string('type', 20)->default('public'); // public, religious, store_closure, special_schedule
            $table->boolean('is_paid')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
        });
        DB::statement('CREATE UNIQUE INDEX holidays_unique_day ON holidays (company_id, COALESCE(store_id, 0), date)');

        Schema::create('salary_structures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('pay_basis', 20)->default('monthly'); // monthly, daily, hourly
            $table->decimal('base_amount', 16, 2);
            $table->decimal('overtime_hourly_rate', 16, 2)->nullable();
            $table->jsonb('components')->nullable(); // [{code,name,type:allowance|deduction,amount}]
            $table->decimal('eobi_employee', 16, 2)->default(0);
            $table->decimal('eobi_employer', 16, 2)->default(0);
            $table->decimal('social_security_employer', 16, 2)->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->index(['contact_id', 'effective_from']);
        });

        Schema::create('payroll_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('store_id')->nullable()->constrained()->nullOnDelete();
            $table->string('number', 30);
            $table->date('period_start');
            $table->date('period_end');
            $table->string('status', 20)->default('draft'); // draft, approved, posted, paid
            $table->unsignedSmallInteger('working_days');
            $table->decimal('gross_total', 16, 2)->default(0);
            $table->decimal('deduction_total', 16, 2)->default(0);
            $table->decimal('net_total', 16, 2)->default(0);
            $table->decimal('employer_cost_total', 16, 2)->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('posted_at')->nullable();
            $table->timestampsTz();
            $table->unique(['company_id', 'number']);
        });
        DB::statement("CREATE UNIQUE INDEX payroll_runs_one_per_period ON payroll_runs (company_id, COALESCE(store_id, 0), period_start)");

        Schema::create('payslips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('payroll_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained()->restrictOnDelete();
            $table->foreignId('salary_structure_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('days_payable', 5, 1)->default(0);
            $table->decimal('days_present', 5, 1)->default(0);
            $table->decimal('days_absent', 5, 1)->default(0);
            $table->decimal('days_paid_leave', 5, 1)->default(0);
            $table->decimal('days_unpaid_leave', 5, 1)->default(0);
            $table->unsignedSmallInteger('late_count')->default(0);
            $table->unsignedInteger('overtime_minutes')->default(0);
            $table->decimal('gross_pay', 16, 2)->default(0);
            $table->decimal('total_deductions', 16, 2)->default(0);
            $table->decimal('net_pay', 16, 2)->default(0);
            $table->decimal('employer_contributions', 16, 2)->default(0);
            $table->string('status', 20)->default('draft'); // draft, final, paid
            $table->foreignId('payment_method_id')->nullable()->constrained()->nullOnDelete();
            $table->string('payment_reference')->nullable();
            $table->timestampTz('paid_at')->nullable();
            $table->timestampsTz();
            $table->unique(['payroll_run_id', 'contact_id']);
        });

        Schema::create('payslip_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payslip_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20); // earning, deduction, employer
            $table->string('code', 40);
            $table->string('description');
            $table->decimal('quantity', 10, 2)->nullable();
            $table->decimal('rate', 16, 4)->nullable();
            $table->decimal('amount', 16, 2);
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
        });

        Schema::create('payroll_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20); // bonus, allowance, deduction, reimbursement
            // eid_bonus, ramadan_allowance, attendance_bonus, fuel, mobile_data,
            // shortage_recovery, damage_recovery, other
            $table->string('category', 30);
            $table->decimal('amount', 16, 2);
            $table->date('payroll_month'); // first day of month it applies to
            $table->text('reason');
            $table->string('investigation_reference')->nullable(); // required for recoveries
            $table->foreignId('evidence_media_id')->nullable()->constrained('media')->nullOnDelete();
            $table->string('status', 20)->default('pending'); // pending, approved, rejected, applied
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->foreignId('payslip_id')->nullable()->constrained()->nullOnDelete();
            $table->timestampsTz();
        });

        Schema::create('employee_advances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('contact_id')->constrained()->restrictOnDelete();
            $table->string('number', 30);
            $table->decimal('amount', 16, 2);
            $table->string('reason')->nullable();
            // requested, approved, rejected, disbursed, settled
            $table->string('status', 20)->default('requested');
            $table->decimal('installment_amount', 16, 2)->nullable();
            $table->date('recovery_start_month')->nullable();
            $table->decimal('recovered_amount', 16, 2)->default(0);
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->foreignId('disbursed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('payment_method_id')->nullable()->constrained()->nullOnDelete();
            $table->timestampTz('disbursed_at')->nullable();
            $table->date('business_date')->nullable();
            $table->string('note')->nullable();
            $table->timestampsTz();
            $table->unique(['company_id', 'number']);
        });

        Schema::create('advance_repayments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('employee_advance_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 16, 2);
            $table->string('source', 20); // payroll, cash
            $table->foreignId('payslip_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('payment_method_id')->nullable()->constrained()->nullOnDelete();
            $table->date('repaid_on');
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
        });

        Schema::create('hr_cases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            // grievance, incident, injury, investigation, disciplinary, compliment, complaint
            $table->string('type', 30);
            $table->string('title');
            $table->text('description');
            $table->string('status', 20)->default('open'); // open, investigating, resolved, closed, appealed
            $table->text('outcome')->nullable();
            $table->text('appeal_note')->nullable();
            $table->date('occurred_on')->nullable();
            $table->date('resolved_on')->nullable();
            $table->foreignId('reported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
        });

        Schema::create('performance_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->jsonb('facts')->nullable(); // objective metrics captured at review time
            $table->text('assessment')->nullable();
            $table->unsignedTinyInteger('rating')->nullable(); // 1-5, manager judgement
            $table->string('status', 20)->default('draft'); // draft, shared, acknowledged, appealed
            $table->text('employee_comment')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        foreach ([
            'performance_reviews', 'hr_cases', 'advance_repayments', 'employee_advances', 'payroll_adjustments',
            'payslip_lines', 'payslips', 'payroll_runs', 'salary_structures', 'holidays', 'leave_requests',
            'leave_allocations', 'leave_types', 'attendance_corrections', 'attendance_records', 'shift_swap_requests',
            'roster_entries', 'work_shifts', 'employee_trainings', 'trainings', 'employee_badge', 'badges',
            'employee_skill', 'skills', 'employee_resume_items', 'employee_assets', 'employee_lifecycle_events',
            'employee_guarantors', 'employee_documents', 'employee_details', 'job_positions', 'departments',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
