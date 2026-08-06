<?php

namespace App\Domains\Assessment\Http\Resources;

use App\Domains\Assessment\Models\AssessmentQuestion;
use App\Domains\Assessment\Models\QuestionOption;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * LEARNER view of a question. This class exists for exactly one reason: to make it impossible to
 * ship the answer key to someone sitting the exam.
 *
 * `is_correct`, option `value` (the accepted-answer text), per-option `feedback` and the question
 * `explanation` are omitted unless `$revealKey` is true — which the caller may only set once the
 * attempt is graded AND the assessment's feedback mode permits it. The author-facing
 * QuestionResource must never be returned from a learner endpoint.
 */
class LearnerQuestionResource extends JsonResource
{
    public function __construct(
        AssessmentQuestion $resource,
        private readonly bool $revealKey = false,
    ) {
        parent::__construct($resource);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var AssessmentQuestion $question */
        $question = $this->resource;

        return [
            'id' => $question->public_id,
            'type' => $question->type->value,
            'prompt' => $question->localized('prompt'),
            'config' => $question->config,
            // A hint is meant to be available during the attempt — that is its whole purpose.
            'hint' => $question->localized('hint'),
            'points' => (float) $question->points,

            // Only revealed after grading, and only when feedback mode allows it.
            'explanation' => $this->revealKey ? $question->localized('explanation') : null,

            'options' => $question->options
                ->map(fn (QuestionOption $option) => $this->option($option))
                ->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function option(QuestionOption $option): array
    {
        $payload = [
            'id' => $option->public_id,
            'label' => $option->localized('label'),
            'group_index' => $option->group_index,
        ];

        // `value` is deliberately absent even when revealing: for text-matched questions it IS the
        // accepted answer, and the learner has already been told whether they were right.
        if ($this->revealKey) {
            $payload['is_correct'] = $option->is_correct;
            $payload['feedback'] = $option->localized('feedback');
        }

        return $payload;
    }
}
