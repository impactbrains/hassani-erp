<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Batch B + C.
 *  - products.deleted_at / deleted_by: the Trash (kept 30 days, then removed).
 *  - stores.timezone: every store gets its own time zone, Asia/Karachi unless changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->timestampTz('deleted_at')->nullable();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->index(['company_id', 'deleted_at']);
        });

        DB::table('stores')->whereNull('timezone')->update(['timezone' => 'Asia/Karachi']);
        DB::statement("ALTER TABLE stores ALTER COLUMN timezone SET DEFAULT 'Asia/Karachi'");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE stores ALTER COLUMN timezone DROP DEFAULT');
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'deleted_at']);
            $table->dropConstrainedForeignId('deleted_by');
            $table->dropColumn('deleted_at');
        });
    }
};
