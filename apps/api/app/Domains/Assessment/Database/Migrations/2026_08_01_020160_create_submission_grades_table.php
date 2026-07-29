<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The CURRENT grade of a submission — exactly one row per submission (unique submission_id),
 * mutated in place. Every mutation bumps `version` (optimistic concurrency): two graders opening
 * the same submission cannot silently overwrite each other, the second write conflicts.
 *
 * rubric_result stores the selected level per criterion; private_notes are grader-only and MUST
 * NOT appear in any learner resource. released_at gates learner visibility of score/feedback.
 * The append-only history lives in submission_grade_events.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('submission_grades', function (Blueprint $table) {
            $table->id();
            $table->publicId();
            $table->foreignId('submission_id')->constrained('assignment_submissions')->cascadeOnDelete();

            // Grader identity is a scalar user id (no cross-context FK).
            $table->unsignedBigInteger('grader_id');

            $table->decimal('score', 8, 2)->nullable();
            $table->boolean('passed')->nullable();
            $table->text('feedback')->nullable();
            $table->text('private_notes')->nullable();
            $table->jsonb('rubric_result')->nullable();

            $table->timestamp('released_at')->nullable();
            $table->unsignedInteger('version')->default(1);

            $table->timestamps();

            $table->unique('submission_id', 'submission_grade_unique');
            $table->index('grader_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('submission_grades');
    }
};
