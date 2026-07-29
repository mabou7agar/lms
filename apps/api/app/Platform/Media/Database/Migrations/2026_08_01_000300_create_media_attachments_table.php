<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P2/W04 - Usage-reference table: records WHERE a media asset is used (a lesson block, an
 * assignment submission, ...) so an asset knows its usage count and cannot be hard-deleted while in
 * use. The attachable is another context's entity, referenced polymorphically by SCALAR
 * (attachable_type + attachable_id) — never a foreign key, never a Media -> other-context import.
 *
 * course_id is denormalized from the asset so a course-scoped authorization check does not have to
 * join back. Unique (attachable_type, attachable_id, media_asset_id) makes attach idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_attachments', function (Blueprint $table): void {
            $table->id();
            $table->publicId();

            $table->foreignId('media_asset_id')->constrained('media_assets')->cascadeOnDelete();

            // Cross-context scalar target — NO foreign key.
            $table->string('attachable_type', 191);
            $table->unsignedBigInteger('attachable_id');

            $table->string('role', 32)->default('attachment');
            $table->unsignedBigInteger('course_id')->nullable();
            $table->unsignedBigInteger('attached_by');

            $table->timestamps();

            $table->unique(['attachable_type', 'attachable_id', 'media_asset_id'], 'media_attachments_unique_usage');
            $table->index('media_asset_id');
            $table->index(['attachable_type', 'attachable_id']);
            $table->index('course_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_attachments');
    }
};
