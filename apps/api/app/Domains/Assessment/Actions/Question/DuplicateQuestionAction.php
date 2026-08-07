<?php

namespace App\Domains\Assessment\Actions\Question;

use App\Domains\Assessment\Models\Assessment;
use App\Domains\Assessment\Models\AssessmentQuestion;
use App\Platform\Shared\Actions\BaseAction;

/**
 * Deep-copies a single question within its own assessment: a fresh public_id, the full localized
 * prompt / explanation / hint maps, the type-specific `config`, points and difficulty, and every
 * option (fresh ids, localized labels / feedback, accepted values, correctness). The copy is
 * appended at the end of the assessment (position = max + 1).
 *
 * Attempt data is NEVER touched. Option ids are referenced by saved answers, so the copy's fresh
 * option ids make it impossible for a duplicate to disturb the grading of an attempt in progress
 * against the source. Copy semantics mirror DuplicateLessonAction's whitelisted field copy — nothing
 * is serialized implicitly, so a column added later is not silently carried across until added here.
 */
class DuplicateQuestionAction extends BaseAction
{
    public function execute(AssessmentQuestion $question): AssessmentQuestion
    {
        return $this->transaction(function () use ($question): AssessmentQuestion {
            $assessmentId = (int) $question->assessment_id;

            // Serialize concurrent appends into the same assessment. Postgres cannot apply FOR UPDATE
            // to an aggregate (max(position)), so the parent assessment row is locked instead —
            // the same style DuplicateLessonAction uses to serialize appends into a section.
            Assessment::whereKey($assessmentId)->lockForUpdate()->first();

            $position = (int) AssessmentQuestion::where('assessment_id', $assessmentId)->max('position') + 1;

            return $this->copyInto($question, $assessmentId, $position)->load('options');
        });
    }

    /**
     * Copy one question row (and its complete option set) into an assessment at a given position.
     * The caller owns the transaction and any locking; this performs only the writes so a whole-
     * assessment duplication can reuse it once per question.
     */
    public function copyInto(AssessmentQuestion $source, int $assessmentId, int $position): AssessmentQuestion
    {
        $copy = AssessmentQuestion::create([
            'assessment_id' => $assessmentId,
            'type' => $source->type->value,
            // Both the localized map and the legacy scalar are carried; the HasTranslations saving
            // hook re-syncs the scalar from the map and re-sanitizes each locale (idempotent — the
            // source was already sanitized on its own write).
            'prompt' => $source->getAttribute('prompt'),
            'prompt_i18n' => $source->getAttribute('prompt_i18n'),
            // jsonb config is carried by value, so per-type keys (case_sensitive, normalize_arabic,
            // partial_credit) survive the copy exactly.
            'config' => $source->config,
            'explanation' => $source->getAttribute('explanation'),
            'explanation_i18n' => $source->getAttribute('explanation_i18n'),
            'hint' => $source->getAttribute('hint'),
            'hint_i18n' => $source->getAttribute('hint_i18n'),
            'points' => $source->getAttribute('points'),
            'negative_points' => $source->getAttribute('negative_points'),
            'difficulty' => $source->difficulty?->value,
            'position' => $position,
        ]);

        $optionPosition = 0;
        foreach ($source->options as $option) {
            $copy->options()->create([
                'label' => $option->getAttribute('label'),
                'label_i18n' => $option->getAttribute('label_i18n'),
                'value' => $option->value,
                'is_correct' => (bool) $option->is_correct,
                'group_index' => (int) $option->group_index,
                'feedback' => $option->getAttribute('feedback'),
                'feedback_i18n' => $option->getAttribute('feedback_i18n'),
                'position' => $optionPosition++,
            ]);
        }

        return $copy;
    }
}
