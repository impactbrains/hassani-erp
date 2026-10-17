<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Double-entry accounting: chart of accounts, fiscal periods, journals and
 * budgets. Accounting is the system of record for all money.
 *
 * Database-enforced rules:
 *  - a posted journal entry and its lines can never be changed or deleted;
 *  - every posted entry must balance (deferred constraint trigger, checked
 *    at commit);
 *  - each line is either a debit or a credit, never negative;
 *  - one automatic entry per (source, purpose) so retries cannot double-post.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->string('code', 20);
            $table->string('name');
            $table->string('name_ur')->nullable();
            $table->string('type', 20); // asset, liability, equity, income, expense
            $table->string('subtype', 30)->nullable();
            $table->string('system_key', 60)->nullable();
            $table->boolean('is_postable')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->unique(['company_id', 'code']);
        });
        DB::statement('CREATE UNIQUE INDEX accounts_system_key_unique ON accounts (company_id, system_key) WHERE system_key IS NOT NULL');

        Schema::table('reason_codes', function (Blueprint $table) {
            $table->foreign('account_id')->references('id')->on('accounts')->nullOnDelete();
        });

        Schema::create('fiscal_years', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('name', 30);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('status', 20)->default('open');
            $table->timestampsTz();
            $table->unique(['company_id', 'starts_on']);
        });

        Schema::create('fiscal_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('fiscal_year_id')->constrained()->cascadeOnDelete();
            $table->string('name', 30);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('status', 20)->default('open'); // open, closed
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('closed_at')->nullable();
            $table->timestampsTz();
            $table->unique(['company_id', 'starts_on']);
        });

        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('store_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('number', 30);
            $table->date('entry_date');
            $table->string('kind', 20)->default('auto'); // auto, manual, opening
            $table->string('status', 20)->default('posted'); // draft (manual only), posted
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('purpose', 40)->nullable();
            $table->string('description');
            $table->foreignId('reversal_of_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('posted_at')->nullable();
            $table->timestampsTz();
            $table->unique(['company_id', 'number']);
            $table->index(['company_id', 'entry_date']);
            $table->index(['source_type', 'source_id']);
        });
        DB::statement('CREATE UNIQUE INDEX journal_entries_source_purpose_unique ON journal_entries (company_id, source_type, source_id, purpose) WHERE source_type IS NOT NULL AND reversal_of_id IS NULL');
        DB::statement('CREATE UNIQUE INDEX journal_entries_single_reversal ON journal_entries (reversal_of_id) WHERE reversal_of_id IS NOT NULL');

        Schema::create('journal_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('journal_entry_id')->constrained()->restrictOnDelete();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->decimal('debit', 16, 2)->default(0);
            $table->decimal('credit', 16, 2)->default(0);
            $table->foreignId('contact_id')->nullable()->constrained()->restrictOnDelete(); // customer/supplier/employee subledger
            $table->foreignId('store_id')->nullable()->constrained()->restrictOnDelete();
            $table->date('entry_date');
            $table->string('memo')->nullable();
            $table->index(['company_id', 'account_id', 'entry_date']);
            $table->index(['company_id', 'contact_id', 'account_id']);
            $table->index('journal_entry_id');
        });
        DB::statement('ALTER TABLE journal_lines ADD CONSTRAINT journal_lines_one_sided CHECK (debit >= 0 AND credit >= 0 AND (debit = 0 OR credit = 0) AND (debit > 0 OR credit > 0))');

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION protect_posted_journal_entry() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF OLD.status = 'posted' THEN
                        RAISE EXCEPTION 'Posted journal entry % cannot be deleted; post a reversal', OLD.number;
                    END IF;
                    RETURN OLD;
                END IF;
                IF OLD.status = 'posted' THEN
                    RAISE EXCEPTION 'Posted journal entry % cannot be changed; post a reversal', OLD.number;
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);
        DB::statement('CREATE TRIGGER journal_entries_protect_posted BEFORE UPDATE OR DELETE ON journal_entries FOR EACH ROW EXECUTE FUNCTION protect_posted_journal_entry()');

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION protect_posted_journal_lines() RETURNS trigger AS $$
            DECLARE
                entry_status text;
            BEGIN
                SELECT status INTO entry_status FROM journal_entries
                    WHERE id = COALESCE(OLD.journal_entry_id, NEW.journal_entry_id);
                IF entry_status = 'posted' AND TG_OP <> 'INSERT' THEN
                    RAISE EXCEPTION 'Lines of a posted journal entry cannot be changed';
                END IF;
                IF TG_OP = 'DELETE' THEN
                    RETURN OLD;
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);
        DB::statement('CREATE TRIGGER journal_lines_protect_posted BEFORE UPDATE OR DELETE ON journal_lines FOR EACH ROW EXECUTE FUNCTION protect_posted_journal_lines()');

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION check_journal_entry_balanced() RETURNS trigger AS $$
            DECLARE
                target_entry bigint;
                entry_status text;
                total_debit numeric;
                total_credit numeric;
                line_count integer;
            BEGIN
                IF TG_TABLE_NAME = 'journal_entries' THEN
                    target_entry := NEW.id;
                ELSE
                    target_entry := NEW.journal_entry_id;
                END IF;
                SELECT status INTO entry_status FROM journal_entries WHERE id = target_entry;
                IF entry_status IS DISTINCT FROM 'posted' THEN
                    RETURN NULL;
                END IF;
                SELECT COALESCE(SUM(debit), 0), COALESCE(SUM(credit), 0), COUNT(*)
                    INTO total_debit, total_credit, line_count
                    FROM journal_lines WHERE journal_entry_id = target_entry;
                IF line_count < 2 OR total_debit <> total_credit THEN
                    RAISE EXCEPTION 'Journal entry % is unbalanced (debit %, credit %, lines %)', target_entry, total_debit, total_credit, line_count;
                END IF;
                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql
        SQL);
        DB::statement('CREATE CONSTRAINT TRIGGER journal_lines_balanced AFTER INSERT ON journal_lines DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION check_journal_entry_balanced()');
        DB::statement('CREATE CONSTRAINT TRIGGER journal_entries_balanced AFTER INSERT OR UPDATE ON journal_entries DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION check_journal_entry_balanced()');

        Schema::create('budgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('store_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->date('month'); // first day of the month
            $table->decimal('amount', 16, 2);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
        });
        DB::statement('CREATE UNIQUE INDEX budgets_unique_slot ON budgets (company_id, COALESCE(store_id, 0), account_id, month)');
    }

    public function down(): void
    {
        Schema::dropIfExists('budgets');
        DB::statement('DROP TRIGGER IF EXISTS journal_entries_balanced ON journal_entries');
        DB::statement('DROP TRIGGER IF EXISTS journal_lines_balanced ON journal_lines');
        DB::statement('DROP TRIGGER IF EXISTS journal_lines_protect_posted ON journal_lines');
        DB::statement('DROP TRIGGER IF EXISTS journal_entries_protect_posted ON journal_entries');
        Schema::dropIfExists('journal_lines');
        Schema::dropIfExists('journal_entries');
        DB::statement('DROP FUNCTION IF EXISTS check_journal_entry_balanced()');
        DB::statement('DROP FUNCTION IF EXISTS protect_posted_journal_lines()');
        DB::statement('DROP FUNCTION IF EXISTS protect_posted_journal_entry()');
        Schema::dropIfExists('fiscal_periods');
        Schema::dropIfExists('fiscal_years');
        Schema::table('reason_codes', function (Blueprint $table) {
            $table->dropForeign(['account_id']);
        });
        Schema::dropIfExists('accounts');
    }
};
