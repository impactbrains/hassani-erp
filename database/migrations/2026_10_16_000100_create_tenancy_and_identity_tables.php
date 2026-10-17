<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tenancy (companies, stores), identity (users, roles, permissions),
 * configuration (settings, sequences), approvals, media and audit trail.
 *
 * Every business table in later migrations carries company_id. Login
 * accounts (users) are deliberately separate from employee profiles;
 * the link lives on contacts.user_id (see HR migration).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('legal_name')->nullable();
            $table->string('ntn')->nullable();
            $table->string('strn')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->string('country', 2)->default('PK');
            $table->string('currency', 3)->default('PKR');
            $table->string('timezone')->default('Asia/Karachi');
            $table->string('default_locale', 5)->default('en');
            $table->string('status')->default('active');
            $table->timestampsTz();
        });

        Schema::create('stores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('code', 20);
            $table->string('name');
            $table->string('name_ur')->nullable();
            $table->string('kind')->default('retail_wholesale'); // retail, wholesale, retail_wholesale, warehouse
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->string('phone')->nullable();
            $table->string('timezone')->nullable(); // null = company timezone
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->unique(['company_id', 'code']);
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password')->nullable();
            $table->string('status')->default('active'); // active, suspended, deactivated
            $table->string('locale', 5)->nullable();
            $table->string('pin_hash')->nullable(); // manager approval PIN (hashed)
            $table->boolean('must_change_password')->default(false);
            $table->text('two_factor_secret')->nullable(); // encrypted
            $table->timestampTz('two_factor_confirmed_at')->nullable();
            $table->timestampTz('email_verified_at')->nullable();
            $table->timestampTz('last_login_at')->nullable();
            $table->timestampTz('last_activity_at')->nullable();
            $table->timestampTz('deactivated_at')->nullable();
            $table->foreignId('deactivated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->rememberToken();
            $table->timestampsTz();
            $table->index(['company_id', 'status']);
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('key', 60);
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_owner')->default(false);
            $table->boolean('is_system')->default(false);
            $table->timestampsTz();
            $table->unique(['company_id', 'key']);
        });

        Schema::create('role_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->string('permission', 100);
            $table->unique(['role_id', 'permission']);
        });

        Schema::create('user_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->restrictOnDelete();
            $table->foreignId('store_id')->nullable()->constrained()->restrictOnDelete(); // null = every store
            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('expires_at')->nullable(); // temporary elevated access
            $table->string('reason')->nullable();
            $table->timestampsTz();
            $table->index(['user_id', 'role_id']);
        });
        DB::statement('CREATE UNIQUE INDEX user_roles_unique_scope ON user_roles (user_id, role_id, COALESCE(store_id, 0))');

        Schema::create('login_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('email')->nullable();
            $table->string('event', 40); // login_succeeded, login_failed, logout, lockout, password_reset, session_revoked
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['company_id', 'created_at']);
            $table->index(['email', 'created_at']);
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('store_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event', 80);
            $table->string('auditable_type')->nullable();
            $table->unsignedBigInteger('auditable_id')->nullable();
            $table->string('description');
            $table->jsonb('old_values')->nullable();
            $table->jsonb('new_values')->nullable();
            $table->jsonb('context')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['company_id', 'created_at']);
            $table->index(['company_id', 'event']);
            $table->index(['auditable_type', 'auditable_id']);
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('store_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('key', 100);
            $table->jsonb('value');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
        });
        DB::statement('CREATE UNIQUE INDEX settings_unique_scope ON settings (company_id, COALESCE(store_id, 0), COALESCE(user_id, 0), key)');

        Schema::create('sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('store_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('key', 40);
            $table->string('prefix', 40);
            $table->unsignedBigInteger('next_number')->default(1);
            $table->unsignedTinyInteger('padding')->default(5);
            $table->timestampsTz();
        });
        DB::statement('CREATE UNIQUE INDEX sequences_unique_scope ON sequences (company_id, COALESCE(store_id, 0), key)');

        Schema::create('media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('disk', 20)->default('public'); // public (product images) or private (documents)
            $table->string('path');
            $table->string('original_name')->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->string('purpose', 40)->default('general');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->index(['company_id', 'purpose']);
        });

        Schema::create('approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('store_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 60);
            $table->string('status', 20)->default('granted'); // granted, consumed, expired
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->constrained('users')->restrictOnDelete();
            $table->jsonb('context')->nullable();
            $table->string('reason')->nullable();
            $table->string('token_hash', 64)->unique();
            $table->timestampTz('expires_at');
            $table->timestampTz('consumed_at')->nullable();
            $table->timestampsTz();
            $table->index(['company_id', 'action', 'created_at']);
        });

        Schema::create('api_clients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('description')->nullable();
            $table->jsonb('abilities');
            $table->foreignId('store_id')->nullable()->constrained()->nullOnDelete(); // default store for orders
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('scope', 60);
            $table->string('key', 100);
            $table->string('request_hash', 64);
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->jsonb('response_body')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['company_id', 'scope', 'key']);
        });
    }

    public function down(): void
    {
        foreach (['idempotency_keys', 'api_clients', 'approvals', 'media', 'sequences', 'settings', 'audit_logs', 'login_events', 'user_roles', 'role_permissions', 'roles', 'users', 'stores', 'companies'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
