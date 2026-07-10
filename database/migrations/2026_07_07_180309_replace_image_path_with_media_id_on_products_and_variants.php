<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('image_path');
            $table->foreignId('media_id')->nullable()->after('is_active')->constrained('media')->nullOnDelete();
        });

        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropColumn('image_path');
            $table->foreignId('media_id')->nullable()->after('is_active')->constrained('media')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('media_id');
            $table->string('image_path')->nullable();
        });

        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropConstrainedForeignId('media_id');
            $table->string('image_path')->nullable();
        });
    }
};