<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (! DB::table('sequences')->where('document_type', 'product_barcode')->exists()) {
            DB::table('sequences')->insert([
                'document_type' => 'product_barcode',
                'prefix' => '20',
                'next_number' => 1,
                'padding' => 10,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('sequences')->where('document_type', 'product_barcode')->delete();
    }
};