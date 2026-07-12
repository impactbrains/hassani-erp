<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per employee Contact (type = 'employee'). This is a 1:1
 * extension table rather than new columns on `contacts`, because these
 * fields are meaningless for customers/suppliers — bolting 40 nullable
 * HR columns onto the shared contacts table would pollute every other
 * contact type's schema for no benefit.
 *
 * Covers the Work tab (department/position/manager/location/note) and
 * the Personal tab (private contact, personal info, emergency contact,
 * visa/permit, citizenship, address, family, education level).
 *
 * Per-day timing/location lives in a separate `employee_work_schedules`
 * table (7 rows per employee) since it's naturally tabular, not flat.
 * Resume experience/education lives in `employee_resume_items` since
 * it's repeatable. Skills and badges are many-to-many, so they get
 * their own pivot tables further down.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->unique()->constrained()->cascadeOnDelete();

            // --- Work: Work ---
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignId('job_position_id')->nullable()->constrained('job_positions')->nullOnDelete();
            $table->string('job_title')->nullable();
            // Manager is another employee Contact, not a User — a manager
            // may or may not have their own login.
            $table->foreignId('manager_contact_id')->nullable()->constrained('contacts')->nullOnDelete();

            // --- Work: Location ---
            $table->string('work_address')->nullable();
            $table->string('work_location')->nullable();

            // --- Work: Note ---
            $table->text('note')->nullable();

            // --- Resume: uploaded file (in addition to the structured
            // resume items table below) ---
            $table->foreignId('resume_file_media_id')->nullable()->constrained('media')->nullOnDelete();

            // --- Personal: Private Contact ---
            $table->string('private_email')->nullable();
            $table->string('private_phone')->nullable();
            $table->string('bank_account')->nullable();

            // --- Personal: Personal Information ---
            $table->string('legal_name')->nullable();
            $table->date('birthday')->nullable();
            $table->string('place_of_birth')->nullable();
            $table->string('birth_country')->nullable();
            $table->string('gender')->nullable();

            // --- Personal: Emergency Contact ---
            $table->string('emergency_contact_name')->nullable();
            $table->string('emergency_contact_phone')->nullable();

            // --- Personal: Visa & Work Permit ---
            $table->string('visa_no')->nullable();
            $table->string('work_permit_no')->nullable();
            $table->foreignId('visa_document_media_id')->nullable()->constrained('media')->nullOnDelete();

            // --- Personal: Citizenship ---
            $table->string('nationality')->nullable();
            $table->string('identification_no')->nullable();
            $table->string('ssn_no')->nullable();
            $table->string('passport_no')->nullable();

            // --- Personal: Location ---
            $table->string('private_address')->nullable();
            $table->string('home_work_distance')->nullable();

            // --- Personal: Family ---
            $table->string('marital_status')->nullable();
            $table->unsignedTinyInteger('dependent_children')->nullable();

            // --- Personal: Education ---
            $table->string('certificate_level')->nullable();
            $table->string('field_of_study')->nullable();

            // --- Personal: Documents ---
            $table->foreignId('id_card_media_id')->nullable()->constrained('media')->nullOnDelete();
            $table->foreignId('driving_license_media_id')->nullable()->constrained('media')->nullOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_details');
    }
};