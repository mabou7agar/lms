<?php

namespace App\Domains\Assessment\Http\Resources;

use App\Platform\Shared\Resources\BaseResource;
use Illuminate\Http\Request;

/** AUTHOR view of an assessment. Questions are included only when explicitly eager-loaded. */
class AssessmentResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->public_id,
            'title' => $this->resource->localized('title'),
            'description' => $this->resource->localized('description'),
            'scope' => $this->resource->scope->value,
            'status' => $this->resource->status->value,
            'version' => $this->resource->version,

            'settings' => [
                'passing_score' => $this->resource->passing_score,
                'negative_marking' => $this->resource->negative_marking,
                'max_attempts' => $this->resource->max_attempts,
                'time_limit_seconds' => $this->resource->time_limit_seconds,
                'shuffle_questions' => $this->resource->shuffle_questions,
                'shuffle_options' => $this->resource->shuffle_options,
                'questions_per_attempt' => $this->resource->questions_per_attempt,
                'feedback_mode' => $this->resource->feedback_mode->value,
            ],

            'question_count' => $this->whenCounted('questions'),
            'questions' => QuestionResource::collection($this->whenLoaded('questions')),
        ];
    }
}
