<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A learner-authored question anchored to a course (and optionally to a specific lesson / timecode).
 *
 * `course_id` is the AUTHORIZATION + tenancy anchor: a question inherits its course's T1
 * "global-OR-own-org" tenancy via CourseTenantScope (no tenant column is queried directly — but
 * `organization_id` is stamped transitively at write time as a denormalised convenience, never
 * mass-assigned). `accepted_answer_id` is added as a nullable column here and the cross-table FK to
 * `question_answers` is attached in the answers migration, which runs afterwards.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_questions', function (Blueprint $table): void {
            $table->id();
            $table->publicId();

            // Authorization + tenancy anchor.
            $table->foreignId('course_id')->constrained('courses')->cascadeOnDelete();
            // Optional lesson anchor; nullable so course-wide questions need no lesson.
            $table->foreignId('lesson_id')->nullable()->constrained('lessons')->nullOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            // Transitive tenancy: derived from the course server-side, NOT fillable. Kept nullable so
            // a global-course question (course.organization_id IS NULL) stays null.
            $table->unsignedBigInteger('organization_id')->nullable();

            $table->string('title');
            $table->text('body'); // sanitized on write

            // Optional deep-link into the lesson media, in seconds.
            $table->unsignedInteger('lesson_timestamp_seconds')->nullable();

            $table->string('status', 16)->default('open'); // QuestionStatus: open|resolved|hidden
            $table->timestamp('pinned_at')->nullable();

            // Cross-table FK attached in the question_answers migration (that table is created after).
            $table->unsignedBigInteger('accepted_answer_id')->nullable();

            $table->unsignedInteger('answers_count')->default(0);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['course_id', 'status']);
            $table->index('lesson_id');
            $table->index('organization_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_questions');
    }
};
