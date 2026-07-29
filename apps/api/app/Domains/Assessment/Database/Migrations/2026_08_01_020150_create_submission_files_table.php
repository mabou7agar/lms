<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A file attached to a submission. The Media asset is referenced only by its PUBLIC id (a scalar
 * string); this context never imports the Media model. Ownership/tenant validation happens through
 * MediaReferencePort before a row is written here. Unique (submission_id, media_public_id) prevents
 * attaching the same asset twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('submission_files', function (Blueprint $table) {
            $table->id();
            $table->publicId();
            $table->foreignId('submission_id')->constrained('assignment_submissions')->cascadeOnDelete();
            // Scalar cross-context reference to a Media asset's public id (resolved via
            // MediaReferencePort). A plain string, NOT a uuid column: it is another context's
            // identifier and this table must not assume its format.
            $table->string('media_public_id');
            $table->string('original_filename')->nullable();
            $table->timestamps();

            $table->index('submission_id');
            $table->unique(['submission_id', 'media_public_id'], 'submission_file_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('submission_files');
    }
};
