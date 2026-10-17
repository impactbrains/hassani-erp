<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reporting: saved views, favourites, recents, audited (queued) exports and
 * versioned demand forecasts with their backtest quality per series.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_saved_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('report_key', 80);
            $table->string('name');
            $table->jsonb('filters');
            $table->boolean('is_default')->default(false);
            $table->timestampsTz();
            $table->index(['user_id', 'report_key']);
        });

        Schema::create('report_favorites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('report_key', 80);
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['user_id', 'report_key']);
        });

        Schema::create('report_recents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('report_key', 80);
            $table->timestampTz('viewed_at');
            $table->unique(['user_id', 'report_key']);
        });

        Schema::create('report_exports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('report_key', 80);
            $table->jsonb('filters');
            $table->string('status', 20)->default('queued'); // queued, running, completed, failed, expired
            $table->boolean('is_sensitive')->default(false);
            $table->unsignedInteger('row_count')->nullable();
            $table->string('file_path')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->string('error')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('downloaded_at')->nullable();
            $table->timestampsTz();
            $table->index(['company_id', 'user_id', 'created_at']);
        });

        Schema::create('forecast_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('store_id')->nullable()->constrained()->nullOnDelete();
            $table->string('level', 20); // variant, category, store
            $table->string('granularity', 10)->default('week');
            $table->unsignedSmallInteger('horizon');
            $table->date('history_start')->nullable();
            $table->date('history_end')->nullable();
            $table->unsignedSmallInteger('backtest_periods');
            $table->string('model_version', 20);
            $table->unsignedInteger('series_count')->default(0);
            $table->string('status', 20)->default('completed');
            $table->jsonb('warnings')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
        });

        Schema::create('forecast_series', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('forecast_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->nullable()->constrained()->nullOnDelete();
            $table->string('level', 20);
            $table->unsignedBigInteger('level_id')->nullable(); // variant id / category id
            $table->string('method', 30);
            $table->unsignedSmallInteger('history_periods');
            $table->unsignedSmallInteger('excluded_stockout_periods')->default(0);
            $table->decimal('mae', 18, 3)->nullable();
            $table->decimal('wape', 9, 4)->nullable();
            $table->decimal('bias', 18, 3)->nullable();
            $table->string('quality', 20); // insufficient_history, experimental, validated
            $table->jsonb('method_scores')->nullable();
            $table->jsonb('drivers')->nullable();
            $table->jsonb('warnings')->nullable();
            $table->timestampsTz();
            $table->index(['forecast_run_id', 'level', 'level_id']);
        });

        Schema::create('demand_forecasts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('forecast_series_id')->constrained('forecast_series')->cascadeOnDelete();
            $table->date('period_start');
            $table->unsignedSmallInteger('horizon_step');
            $table->decimal('predicted', 18, 3);
            $table->decimal('lower', 18, 3)->nullable();
            $table->decimal('upper', 18, 3)->nullable();
            $table->index(['forecast_series_id', 'period_start']);
        });
    }

    public function down(): void
    {
        foreach (['demand_forecasts', 'forecast_series', 'forecast_runs', 'report_exports', 'report_recents', 'report_favorites', 'report_saved_views'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
