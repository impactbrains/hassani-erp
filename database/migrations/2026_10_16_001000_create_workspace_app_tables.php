<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tables for the workspace apps that existed before this rebuild and are
 * kept working: to-do, team chat, recruitment (ATS + careers page), the
 * website page builder and the barcode label pool. They now carry
 * company_id like every other business table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status', 20)->default('to_do'); // to_do, in_progress, done, cancelled
            $table->string('priority', 10)->default('medium'); // low, medium, high
            $table->date('due_date')->nullable();
            $table->time('due_time')->nullable();
            $table->foreignId('created_by_contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->timestampsTz();
            $table->index(['company_id', 'status', 'due_date']);
        });

        Schema::create('task_contact', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->timestampsTz();
            $table->unique(['task_id', 'contact_id']);
        });

        Schema::create('chat_channels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('name')->nullable();
            $table->string('type', 10); // group, dm
            $table->foreignId('created_by_contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->timestampsTz();
        });

        Schema::create('chat_channel_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('channel_id')->constrained('chat_channels')->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->boolean('can_manage')->default(false);
            $table->boolean('is_notifications_muted')->default(false);
            $table->boolean('is_restricted')->default(false);
            $table->timestampTz('joined_at')->useCurrent();
            $table->timestampsTz();
            $table->unique(['channel_id', 'contact_id']);
        });

        Schema::create('chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('channel_id')->constrained('chat_channels')->cascadeOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->text('body')->nullable();
            $table->timestampsTz();
            $table->index(['channel_id', 'created_at']);
        });

        Schema::create('chat_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained('chat_messages')->cascadeOnDelete();
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->timestampsTz();
        });

        Schema::create('job_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->timestampsTz();
            $table->unique(['company_id', 'name']);
        });

        Schema::create('ats_skills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->timestampsTz();
            $table->unique(['company_id', 'name']);
        });

        Schema::create('ats_skill_job_category', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ats_skill_id')->constrained('ats_skills')->cascadeOnDelete();
            $table->foreignId('job_category_id')->constrained('job_categories')->cascadeOnDelete();
            $table->timestampsTz();
            $table->unique(['ats_skill_id', 'job_category_id']);
        });

        Schema::create('job_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->timestampsTz();
            $table->unique(['company_id', 'name']);
        });

        Schema::create('jobs_ats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->foreignId('job_category_id')->nullable()->constrained('job_categories')->nullOnDelete();
            $table->foreignId('job_type_id')->nullable()->constrained('job_types')->nullOnDelete();
            $table->unsignedInteger('total_positions')->default(1);
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->string('status', 20)->default('draft'); // draft, published, closed
            $table->string('work_experience')->nullable();
            $table->boolean('show_salary')->default(false);
            $table->decimal('salary_min', 16, 2)->nullable();
            $table->decimal('salary_max', 16, 2)->nullable();
            $table->boolean('ask_gender')->default(false);
            $table->boolean('ask_country')->default(false);
            $table->boolean('ask_address')->default(false);
            $table->boolean('show_profile_image')->default(false);
            $table->boolean('show_resume')->default(false);
            $table->boolean('show_cover_letter')->default(false);
            $table->boolean('show_terms_and_conditions')->default(false);
            $table->foreignId('created_by_contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->timestampsTz();
            $table->index(['company_id', 'status']);
        });

        Schema::create('ats_skill_job_ats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_ats_id')->constrained('jobs_ats')->cascadeOnDelete();
            $table->foreignId('ats_skill_id')->constrained('ats_skills')->cascadeOnDelete();
            $table->timestampsTz();
            $table->unique(['job_ats_id', 'ats_skill_id']);
        });

        Schema::create('job_form_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_ats_id')->constrained('jobs_ats')->cascadeOnDelete();
            $table->string('label');
            $table->string('field_type', 20); // text, textarea, number, date, dropdown, checkbox, radio, file
            $table->jsonb('options')->nullable();
            $table->boolean('is_required')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestampsTz();
        });

        Schema::create('application_stages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_terminal')->default(false);
            $table->timestampsTz();
        });

        Schema::table('contacts', function (Blueprint $table) {
            $table->foreignId('application_stage_id')->nullable()->constrained('application_stages')->nullOnDelete();
            $table->foreignId('job_ats_id')->nullable()->constrained('jobs_ats')->nullOnDelete();
            $table->string('track', 20)->nullable(); // employee, supplier (candidate pipelines)
            $table->text('application_notes')->nullable();
            $table->jsonb('custom_field_answers')->nullable();
            $table->timestampTz('applied_at')->nullable();
        });

        Schema::create('websites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('subdomain')->unique()->nullable();
            $table->string('custom_domain')->unique()->nullable();
            $table->boolean('is_published')->default(false);
            $table->timestampTz('published_at')->nullable();
            $table->unsignedBigInteger('homepage_id')->nullable();
            $table->timestampsTz();
        });

        Schema::create('website_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('website_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('slug');
            $table->boolean('is_homepage')->default(false);
            $table->string('status', 20)->default('draft');
            $table->string('meta_title')->nullable();
            $table->text('meta_description')->nullable();
            $table->foreignId('og_image_id')->nullable()->constrained('media')->nullOnDelete();
            $table->timestampTz('published_at')->nullable();
            $table->timestampsTz();
            $table->unique(['website_id', 'slug']);
        });

        Schema::table('websites', function (Blueprint $table) {
            $table->foreign('homepage_id')->references('id')->on('website_pages')->nullOnDelete();
        });

        // Block catalog and page templates are platform-wide building blocks.
        Schema::create('block_definitions', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->string('category')->default('general');
            $table->text('description')->nullable();
            $table->unsignedBigInteger('thumbnail_id')->nullable();
            $table->jsonb('content_schema');
            $table->jsonb('default_content');
            $table->jsonb('default_style')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestampsTz();
        });

        Schema::create('page_blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('website_page_id')->constrained()->cascadeOnDelete();
            $table->foreignId('block_definition_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->jsonb('content');
            $table->jsonb('style')->nullable();
            $table->boolean('is_visible')->default(true);
            $table->timestampsTz();
            $table->index(['website_page_id', 'position']);
        });

        Schema::create('page_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('category')->default('general');
            $table->text('description')->nullable();
            $table->unsignedBigInteger('thumbnail_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestampsTz();
        });

        Schema::create('page_template_blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('page_template_id')->constrained()->cascadeOnDelete();
            $table->foreignId('block_definition_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->jsonb('content');
            $table->jsonb('style')->nullable();
            $table->timestampsTz();
            $table->index(['page_template_id', 'position']);
        });

        Schema::create('website_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('website_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('ga4_measurement_id')->nullable();
            $table->string('gtm_container_id')->nullable();
            $table->text('custom_head_code')->nullable();
            $table->text('custom_body_code')->nullable();
            $table->foreignId('favicon_media_id')->nullable()->constrained('media')->nullOnDelete();
            $table->string('default_meta_title')->nullable();
            $table->text('default_meta_description')->nullable();
            $table->timestampsTz();
        });

        Schema::create('generated_barcodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('code', 64);
            $table->string('label')->nullable();
            $table->string('status', 20)->default('unclaimed'); // unclaimed, claimed
            $table->timestampsTz();
            $table->unique(['company_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generated_barcodes');
        Schema::dropIfExists('website_settings');
        Schema::dropIfExists('page_template_blocks');
        Schema::dropIfExists('page_templates');
        Schema::dropIfExists('page_blocks');
        Schema::dropIfExists('block_definitions');
        Schema::table('websites', function (Blueprint $table) {
            $table->dropForeign(['homepage_id']);
        });
        Schema::dropIfExists('website_pages');
        Schema::dropIfExists('websites');
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('application_stage_id');
            $table->dropConstrainedForeignId('job_ats_id');
            $table->dropColumn(['track', 'application_notes', 'custom_field_answers', 'applied_at']);
        });
        foreach (['application_stages', 'job_form_fields', 'ats_skill_job_ats', 'jobs_ats', 'job_types', 'ats_skill_job_category', 'ats_skills', 'job_categories', 'chat_attachments', 'chat_messages', 'chat_channel_members', 'chat_channels', 'task_contact', 'tasks'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
