<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * U8 - Ordered course gallery. Each row references a media asset by its cross-context `public_id`
 * string (the same reference the MediaPicker stores and MediaReferencePort resolves) — deliberately a
 * plain scalar, not an Eloquent FK, so the Catalog domain never hard-links a Platform\Media row.
 * Deleting a gallery item removes only the ordering row; the shared MediaAsset is untouched. Rows are
 * cascaded when their parent course is force-deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_gallery_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained('courses')->cascadeOnDelete();
            $table->string('media_public_id')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['course_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_gallery_items');
    }
};
