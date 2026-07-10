<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('taxes', function (Blueprint $table) {
            $table->boolean('is_default')->default(false)->after('is_active');
        });

        // Restore the Standard tax that existed before the table was wiped.
        DB::table('taxes')->insert([
            'name' => 'Standard',
            'rate' => 0,
            'type' => 'percentage',
            'is_active' => true,
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::table('taxes', function (Blueprint $table) {
            $table->dropColumn('is_default');
        });
    }
};