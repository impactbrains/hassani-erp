<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE documents MODIFY COLUMN type ENUM('pos_order', 'pos_refund', 'invoice', 'sales_order', 'credit_note') NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE documents MODIFY COLUMN type ENUM('pos_order', 'invoice', 'sales_order', 'credit_note') NOT NULL");
    }
};