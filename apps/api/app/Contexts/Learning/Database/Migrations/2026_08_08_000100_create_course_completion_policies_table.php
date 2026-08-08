<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-course, opt-in completion policy. ONE row per course, keyed BY course_id (the primary key), so
 * a course either has a policy or does not — there is no separate surrogate id to reconcile.
 *
 * The critical invariant: a course with NO row behaves EXACTLY as before this table existed —
 * "100% of published lessons complete". {@see \App\Contexts\Learning\Support\CompletionPolicy::default()}
 * is that behaviour expressed as a value object (require_all_lessons = true, every other rule off),
 * and {@see \App\Contexts\Learning\Services\CourseCompletionPolicyResolver} returns it when the row
 * is absent. Storing a row only ever ADDS gates on top of (or, if require_all_lessons is disabled,
 * replaces) the lesson rule.
 *
 * final_exam_assessment_id is a real FK to assessments (nullOnDelete) — the same cross-table anchor
 * assessments.course_id already uses to reach courses; the policy engine reads attempt outcomes for
 * it only through the AssessmentResultPort, never an Assessment model.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_completion_policies', function (Blueprint $table) {
            // course_id IS the primary key: one policy per course, no surrogate id.
            $table->unsignedBigInteger('course_id')->primary();

            // The lesson rule. Default true reproduces today's "all published lessons" behaviour.
            $table->boolean('require_all_lessons')->default(true);

            // null = off. When set (0-100), aggregate watched vs total video duration must reach it.
            $table->unsignedTinyInteger('min_watch_percentage')->nullable();

            // Gate on every course assessment flagged required_for_completion being passed.
            $table->boolean('require_required_quizzes')->default(false);

            // Gate on one specific final exam being passed.
            $table->boolean('require_final_exam')->default(false);
            $table->foreignId('final_exam_assessment_id')->nullable()
                ->constrained('assessments')->nullOnDelete();

            // Gate on every required assignment in the course (reuses AssignmentRequirementPort).
            $table->boolean('require_required_assignments')->default(false);

            $table->timestamps();

            $table->foreign('course_id')->references('id')->on('courses')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_completion_policies');
    }
};
