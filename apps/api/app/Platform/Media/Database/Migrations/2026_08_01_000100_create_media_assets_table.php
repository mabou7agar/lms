<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P2/W04 - The root media asset row: one per uploaded piece of media, from direct-upload creation
 * through provider processing to readiness. Owns the single-use upload token (folded on here rather
 * than a separate sessions table) so a finalize call can never be replayed.
 *
 * Boundary rules (PostgreSQL, the app/test driver):
 *  - created_by / course_id are CROSS-CONTEXT scalar references (Identity user / Catalog course) —
 *    stored as plain unsigned bigints WITH an index and NO foreign key.
 *  - provider_ref / upload_token are plain unique: on Postgres NULLs are distinct, so many pre-
 *    upload rows may hold NULL while every issued value stays unique.
 *  - unique (created_by, idempotency_key) makes a retried createDirectUpload a no-op.
 *  - CHECK processing_progress BETWEEN 0 AND 100.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_assets', function (Blueprint $table): void {
            $table->id();
            $table->publicId();

            $table->string('type', 16);
            $table->string('status', 32)->default('created');
            $table->string('provider', 16);
            $table->string('purpose', 32);

            // Cross-context scalar refs — indexed, never foreign-keyed (DDD boundary).
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('course_id')->nullable();

            $table->string('original_filename')->nullable();
            $table->string('mime_type', 191)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();

            // Provider identifiers — server-side only, never serialized to a client.
            $table->string('provider_ref')->nullable()->unique();
            $table->string('playback_id')->nullable();
            $table->string('storage_key')->nullable();
            $table->string('thumbnail_ref')->nullable();

            $table->unsignedTinyInteger('processing_progress')->default(0);
            $table->string('failure_code', 64)->nullable();
            $table->text('failure_message')->nullable();

            $table->jsonb('metadata')->nullable();

            // Idempotency of upload creation + the single-use, expiring finalize token.
            $table->string('idempotency_key', 128);
            $table->string('upload_token', 128)->nullable()->unique();
            $table->timestamp('upload_token_expires_at')->nullable();
            $table->timestamp('upload_token_consumed_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['created_by', 'idempotency_key']);
            $table->index('status');
            $table->index('provider');
            $table->index('created_by');
            $table->index('course_id');
            $table->index(['created_by', 'status']);
        });

        DB::statement(
            'ALTER TABLE media_assets ADD CONSTRAINT media_assets_progress_check '
            .'CHECK (processing_progress BETWEEN 0 AND 100)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('media_assets');
    }
};
