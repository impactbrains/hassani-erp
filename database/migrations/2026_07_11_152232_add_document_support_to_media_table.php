<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Your Media model currently only distinguishes `type` = 'upload' | 'url',
 * and resolvedUrl() assumes every upload is an image living in
 * uploads/media/. HR needs to store non-image files (resumes, ID scans,
 * visa docs, driving licenses) through the same picker, so we:
 *
 *  - add `original_name` (the filename the user uploaded, since HR docs
 *    need a readable label like "Muhammad_ID.pdf" rather than a hashed path)
 *  - add `mime_type` (so the frontend can decide: render an <img>, or show
 *    a generic file icon + download link, for PDFs/docs)
 *  - add `size` (bytes) purely for display ("2.4 MB") — optional but cheap
 *    to capture at upload time and annoying to backfill later
 *
 * We do NOT change the `type` enum or resolvedUrl()'s path logic — a PDF
 * upload is still `type = 'upload'`, still lands in uploads/media/, it
 * just now carries enough metadata for the frontend to render it sensibly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media', function (Blueprint $table) {
            $table->string('original_name')->nullable()->after('name');
            $table->string('mime_type')->nullable()->after('original_name');
            $table->unsignedBigInteger('size')->nullable()->after('mime_type');
        });
    }

    public function down(): void
    {
        Schema::table('media', function (Blueprint $table) {
            $table->dropColumn(['original_name', 'mime_type', 'size']);
        });
    }
};