<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Parties (customers, suppliers, employees, candidates) and CRM records.
 *
 * Customer credit balances (Khata) are NOT stored here: they are derived
 * from the accounts-receivable journal lines, the single financial source
 * of truth. CRM only stores limits, terms and relationship history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('type', 20); // customer, supplier, employee, candidate
            $table->string('code', 30)->nullable();
            $table->string('name');
            $table->string('name_ur')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('phone_normalized', 20)->nullable();
            $table->string('alt_phone', 30)->nullable();
            $table->string('email')->nullable();
            $table->string('address')->nullable();
            $table->string('area')->nullable();
            $table->string('city')->nullable();
            $table->text('delivery_instructions')->nullable();
            $table->string('tax_number')->nullable();
            $table->string('preferred_language', 5)->nullable();
            $table->foreignId('store_id')->nullable()->constrained()->nullOnDelete(); // home store
            $table->foreignId('user_id')->nullable()->unique()->constrained()->nullOnDelete(); // login link (employees)
            $table->foreignId('merged_into_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->jsonb('meta')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->index(['company_id', 'type', 'is_active']);
            $table->index(['company_id', 'phone_normalized']);
        });
        DB::statement('CREATE INDEX contacts_name_trgm ON contacts USING gin (lower(name) gin_trgm_ops)');
        DB::statement("CREATE UNIQUE INDEX contacts_code_unique ON contacts (company_id, type, code) WHERE code IS NOT NULL");

        Schema::create('customer_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('contact_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('category', 20)->default('retail'); // retail, wholesale, delivery, recurring
            $table->foreignId('price_book_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('credit_limit', 16, 2)->default(0); // 0 = cash only
            $table->unsignedSmallInteger('payment_terms_days')->default(0);
            $table->string('credit_status', 20)->default('active'); // active, on_hold, blocked
            $table->string('credit_status_reason')->nullable();
            $table->foreignId('sales_agent_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->boolean('marketing_consent')->default(false);
            $table->jsonb('consent_channels')->nullable();
            $table->timestampTz('consent_recorded_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('supplier_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('contact_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('lead_time_days')->default(3);
            $table->unsignedSmallInteger('payment_terms_days')->default(0);
            $table->decimal('min_order_value', 16, 2)->default(0);
            $table->text('bank_details')->nullable(); // encrypted
            $table->timestampsTz();
        });

        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('name', 60);
            $table->string('color', 20)->default('slate');
            $table->timestampsTz();
            $table->unique(['company_id', 'name']);
        });

        Schema::create('contact_tag', function (Blueprint $table) {
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->primary(['contact_id', 'tag_id']);
        });

        Schema::create('crm_interactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('channel', 20); // call, visit, whatsapp, sms, email, in_store, note
            $table->text('summary');
            $table->boolean('is_sensitive')->default(false);
            $table->timestampTz('occurred_at');
            $table->timestampsTz();
            $table->index(['contact_id', 'occurred_at']);
        });

        Schema::create('crm_cases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('store_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->string('number', 30);
            $table->string('type', 30); // complaint, return_request, delivery_issue, quality, billing, other
            $table->string('subject');
            $table->text('description')->nullable();
            $table->string('priority', 10)->default('normal');
            $table->string('status', 20)->default('open'); // open, in_progress, resolved, closed
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('related_type')->nullable();
            $table->unsignedBigInteger('related_id')->nullable();
            $table->text('resolution')->nullable();
            $table->timestampTz('due_at')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->unique(['company_id', 'number']);
            $table->index(['company_id', 'status']);
        });

        Schema::create('crm_follow_ups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->text('notes')->nullable();
            $table->string('priority', 10)->default('normal');
            $table->string('status', 20)->default('open'); // open, done, cancelled
            $table->timestampTz('due_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->index(['company_id', 'status', 'due_at']);
        });

        Schema::create('payment_promises', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 16, 2);
            $table->date('promised_date');
            $table->string('status', 20)->default('pending'); // pending, kept, broken, cancelled
            $table->text('note')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampsTz();
            $table->index(['company_id', 'status', 'promised_date']);
        });

        Schema::create('customer_segments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->jsonb('rules'); // [{field, operator, value}] combined with AND
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
        });

        Schema::create('contact_merges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('survivor_id')->constrained('contacts')->restrictOnDelete();
            $table->unsignedBigInteger('merged_id');
            $table->jsonb('merged_snapshot');
            $table->jsonb('moved_records');
            $table->foreignId('merged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        foreach (['contact_merges', 'customer_segments', 'payment_promises', 'crm_follow_ups', 'crm_cases', 'crm_interactions', 'contact_tag', 'tags', 'supplier_profiles', 'customer_profiles', 'contacts'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
