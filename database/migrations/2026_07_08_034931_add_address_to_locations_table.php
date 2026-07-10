<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->string('address')->nullable()->after('type');
        });

        // Seed the default location if none exists yet.
        if (! DB::table('locations')->where('is_default', true)->exists()) {
            DB::table('locations')->insert([
                'name' => 'Hassani Bachat Bazar Location 1',
                'type' => 'store',
                'address' => null,
                'is_default' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->dropColumn('address');
        });
    }
};