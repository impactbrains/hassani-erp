<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Purchase stock: goods received that are billed (and paid) in the same step.
 *  - goods_receipts: how it was paid, the bill and payment it created, and who approved it.
 *    A purchase above the approval value waits as "pending_approval" until a manager approves it.
 *  - Permission inventory.purchase_stock for the Owner, Store manager and Inventory manager roles.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('goods_receipts', function (Blueprint $table) {
            $table->string('settlement', 20)->nullable(); // null or bill_later, paid_now, on_credit
            $table->foreignId('payment_method_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('amount_paid', 16, 2)->nullable();
            $table->string('payment_reference', 80)->nullable();
            $table->foreignId('supplier_bill_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('decision_note', 300)->nullable();
            $table->index(['company_id', 'status']);
        });

        // Owner roles list every permission too, so the Roles screen shows it ticked for them.
        $roles = DB::table('roles')->where(fn ($q) => $q->whereIn('key', ['store_manager', 'inventory_manager'])->orWhere('is_owner', true))->pluck('id');
        foreach ($roles as $roleId) {
            DB::table('role_permissions')->insertOrIgnore(['role_id' => $roleId, 'permission' => 'inventory.purchase_stock']);
        }
    }

    public function down(): void
    {
        DB::table('role_permissions')->where('permission', 'inventory.purchase_stock')->delete();
        Schema::table('goods_receipts', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'status']);
            $table->dropConstrainedForeignId('payment_method_id');
            $table->dropConstrainedForeignId('supplier_bill_id');
            $table->dropConstrainedForeignId('payment_id');
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn(['settlement', 'amount_paid', 'payment_reference', 'decision_note']);
        });
    }
};
