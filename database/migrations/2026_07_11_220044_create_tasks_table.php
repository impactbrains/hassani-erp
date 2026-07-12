<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Internal staff to-do tracking. `status` and `priority` are plain
 * string enums (matching your existing pattern elsewhere — e.g.
 * documents.status, contacts.type — rather than separate lookup
 * tables, since these are small, fixed, code-defined sets that don't
 * need admin-editable management screens the way Departments/Skills
 * do).
 *
 * `created_by_contact_id` records which employee (via their Contact
 * row, same as everywhere else in this app) created/assigned the task
 * — nullable so a task can survive if that employee's Contact is later
 * deleted, rather than cascading the whole task away.
 *
 * Assignment is many-to-many via `task_contact` (a task can go to
 * multiple employees), not a single `assigned_to_contact_id` column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            $table->enum('status', ['to_do', 'in_progress', 'done', 'cancelled'])->default('to_do');
            $table->enum('priority', ['low', 'medium', 'high'])->default('medium');
            $table->date('due_date')->nullable();
            $table->time('due_time')->nullable();
            $table->foreignId('created_by_contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'due_date']);
        });

        Schema::create('task_contact', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['task_id', 'contact_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_contact');
        Schema::dropIfExists('tasks');
    }
};