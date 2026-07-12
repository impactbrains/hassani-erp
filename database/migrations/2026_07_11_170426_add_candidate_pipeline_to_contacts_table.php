<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Widen the enum to allow 'candidate' as a type value.
        DB::statement("ALTER TABLE contacts MODIFY COLUMN type ENUM('customer', 'supplier', 'employee', 'candidate') NOT NULL");

        Schema::table('contacts', function (Blueprint $table) {
            // What to revert `type` back to if this candidate is rejected
            // (e.g. they were already a customer before applying for a
            // job). Null means there's nothing to revert to — they were a
            // fresh public applicant with no prior relationship.
            $table->string('previous_type')->nullable()->after('type');

            // Which pipeline this candidate is in — powers the filtered
            // Candidates sub-tab under both Employees and Suppliers.
            $table->enum('track', ['employee', 'supplier'])->nullable()->after('previous_type');

            $table->enum('stage', ['applied', 'reviewing', 'interview', 'hired', 'rejected'])
                ->nullable()
                ->after('track');

            $table->text('application_notes')->nullable()->after('stage');
            $table->timestamp('applied_at')->nullable()->after('application_notes');
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn(['previous_type', 'track', 'stage', 'application_notes', 'applied_at']);
        });

        DB::statement("ALTER TABLE contacts MODIFY COLUMN type ENUM('customer', 'supplier', 'employee') NOT NULL");
    }
};