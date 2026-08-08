<?php

namespace App\Contexts\Learning\Support;

use App\Contexts\Learning\Models\CourseCompletionPolicy;

/**
 * Immutable value object describing HOW a course decides completion. Decoupled from the Eloquent row
 * on purpose: the {@see CourseCompletionEvaluator} composes rules from this object, and {@see default()}
 * expresses the pre-existing hardcoded behaviour — "all published lessons complete, nothing else" —
 * so a course with no stored policy row and a course with an explicit default row are indistinguishable
 * to the engine.
 */
final class CompletionPolicy
{
    public function __construct(
        public readonly bool $requireAllLessons,
        public readonly ?int $minWatchPercentage,
        public readonly bool $requireRequiredQuizzes,
        public readonly bool $requireFinalExam,
        public readonly ?int $finalExamAssessmentId,
        public readonly bool $requireRequiredAssignments,
    ) {}

    /**
     * The behaviour a course has when it carries NO policy row: complete when 100% of published
     * lessons are complete, and no other gate. This MUST equal the rule ProgressService enforced
     * before the policy engine existed.
     */
    public static function default(): self
    {
        return new self(
            requireAllLessons: true,
            minWatchPercentage: null,
            requireRequiredQuizzes: false,
            requireFinalExam: false,
            finalExamAssessmentId: null,
            requireRequiredAssignments: false,
        );
    }

    public static function fromModel(CourseCompletionPolicy $model): self
    {
        $minWatch = $model->min_watch_percentage;
        $finalExamId = $model->final_exam_assessment_id;

        return new self(
            requireAllLessons: (bool) $model->require_all_lessons,
            minWatchPercentage: $minWatch === null ? null : (int) $minWatch,
            requireRequiredQuizzes: (bool) $model->require_required_quizzes,
            requireFinalExam: (bool) $model->require_final_exam,
            finalExamAssessmentId: $finalExamId === null ? null : (int) $finalExamId,
            requireRequiredAssignments: (bool) $model->require_required_assignments,
        );
    }
}
