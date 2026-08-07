<?php

namespace App\Domains\Assessment\Actions\Assessment;

use App\Domains\Assessment\Actions\Question\DuplicateQuestionAction;
use App\Domains\Assessment\Enums\AssessmentStatus;
use App\Domains\Assessment\Models\Assessment;
use App\Platform\Shared\Actions\BaseAction;
use App\Platform\Shared\Audit\AuditLogger;

/**
 * Deep-copies an assessment: a fresh public_id, every question and option (also fresh ids), the full
 * localized *_i18n maps, each question's type-specific `config`, and any objective / tag
 * associations. The copy is forced to Draft and starts its own lineage (version 1, no parent) — a
 * duplicate is an independent new assessment, not a new version of the source, and could not pass
 * the publish guard on birth anyway.
 *
 * Attempts, answers and results are NEVER copied: only the questions / options graph is walked, so a
 * learner's history stays with the original. Copy semantics mirror DuplicateLessonAction's
 * whitelisted field copy — nothing is serialized implicitly.
 */
class DuplicateAssessmentAction extends BaseAction
{
    public function __construct(
        private readonly DuplicateQuestionAction $questionCopier,
        private readonly AuditLogger $audit,
    ) {}

    public function execute(Assessment $assessment, ?int $actorId = null): Assessment
    {
        return $this->transaction(function () use ($assessment, $actorId): Assessment {
            // Lock the source row so a concurrent question edit / reorder cannot interleave with the
            // deep copy and produce an inconsistent duplicate — DuplicateLessonAction's locking style.
            Assessment::whereKey($assessment->id)->lockForUpdate()->first();

            $copy = Assessment::create([
                'course_id' => $assessment->course_id,
                'title' => $assessment->getAttribute('title'),
                'title_i18n' => $assessment->getAttribute('title_i18n'),
                'description' => $assessment->getAttribute('description'),
                'description_i18n' => $assessment->getAttribute('description_i18n'),
                'scope' => $assessment->scope->value,
                // Never born published: the copy must earn publication on its own merits.
                'status' => AssessmentStatus::Draft->value,
                'passing_score' => $assessment->passing_score,
                'negative_marking' => (bool) $assessment->negative_marking,
                'max_attempts' => $assessment->max_attempts,
                'time_limit_seconds' => $assessment->time_limit_seconds,
                'shuffle_questions' => (bool) $assessment->shuffle_questions,
                'shuffle_options' => (bool) $assessment->shuffle_options,
                'questions_per_attempt' => $assessment->questions_per_attempt,
                'feedback_mode' => $assessment->feedback_mode->value,
                // A duplicate starts a fresh lineage rather than versioning the source.
                'version' => 1,
                'parent_assessment_id' => null,
                'created_by' => $actorId,
            ]);

            // Questions come off the relation already ordered by position; re-index the copy from 1
            // so the duplicate has a clean, gap-free ordering regardless of the source's sparseness.
            $position = 1;
            foreach ($assessment->questions as $question) {
                $this->questionCopier->copyInto($question, (int) $copy->id, $position++);
            }

            // Objective / tag associations are authoring metadata, not attempt data — carry them.
            $tagIds = $assessment->tags->pluck('id')->all();
            if ($tagIds !== []) {
                $copy->tags()->sync($tagIds);
            }

            $this->audit->log('assessment.duplicated', $copy, [
                'source_id' => (int) $assessment->id,
                'source_public_id' => (string) $assessment->public_id,
            ], $actorId);

            return $copy->load('questions.options');
        });
    }
}
