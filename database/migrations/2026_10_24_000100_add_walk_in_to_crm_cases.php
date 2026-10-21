<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Complaints from walk-in customers: a case with no customer record (contact_id empty) can carry the
 * walk-in customer's name and phone, so there is someone to call back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_cases', function (Blueprint $table) {
            $table->string('walk_in_name', 120)->nullable();
            $table->string('walk_in_phone', 30)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('crm_cases', function (Blueprint $table) {
            $table->dropColumn(['walk_in_name', 'walk_in_phone']);
        });
    }
};
