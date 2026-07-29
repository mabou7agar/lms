<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P2/W02 - Content Block model (additive, dormant behind the `authoring.blocks_enabled` flag).
 *
 * Adds two new tables and touches NOTHING existing:
 *  - authoring_modules: optional nested grouping above/around Sections (course-scoped, self-nesting).
 *  - content_blocks:    first-class typed content unit belonging to a Lesson (promotes the block
 *                       payloads the frontend already stores inside lessons.content).
 *
 * Mirrors the existing Authoring conventions: publicId() external id, publish_state string,
 * position, soft deletes, course/lesson foreign keys. No column on existing tables is modified.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('authoring_modules', function (Blueprint $table): void {
            $table->id();
            $table->publicId();
            $table->foreignId('course_id')->constrained('courses')->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('authoring_modules')->nullOnDelete();
            $table->string('title');
            $table->string('summary')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->string('publish_state')->default('draft');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['course_id', 'parent_id', 'position']);
        });

        Schema::create('content_blocks', function (Blueprint $table): void {
            $table->id();
            $table->publicId();
            $table->foreignId('lesson_id')->constrained('lessons')->cascadeOnDelete();
            $table->string('family');
            $table->string('type');
            $table->jsonb('payload')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->string('publish_state')->default('draft');
            // Forward hook for W05 (Content Library) - a block MAY reference a reusable object.
            // Nullable + no FK yet: the learning_objects table arrives in a later wave.
            $table->unsignedBigInteger('learning_object_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['lesson_id', 'position']);
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_blocks');
        Schema::dropIfExists('authoring_modules');
    }
};
