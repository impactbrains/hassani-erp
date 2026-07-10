<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('app'); // which app's reporting tab this belongs to
            $table->string('action'); // created, updated, deleted, confirmed, voided, stock_adjusted, etc.
            $table->string('description');
            $table->nullableMorphs('subject'); // subject_type, subject_id
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['app', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};