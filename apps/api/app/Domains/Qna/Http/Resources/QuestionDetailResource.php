<?php

declare(strict_types=1);

namespace App\Domains\Qna\Http\Resources;

use App\Domains\Qna\Models\CourseQuestion;
use App\Domains\Qna\Models\QuestionAnswer;
use App\Platform\Identity\Contracts\Data\UserRef;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * Full question view with its answers embedded. Author identities are supplied as a pre-resolved
 * map (user id -> UserRef) so the whole thread renders with a SINGLE UserLookupPort call and never
 * leaks PII beyond {id: public_id, name}. Answers are ordered accepted-first, then oldest-first.
 */
class QuestionDetailResource extends JsonResource
{
    /**
     * @param  Collection<int, QuestionAnswer>  $answers
     * @param  array<int, UserRef>  $authors  keyed by internal user id
     */
    public function __construct(
        CourseQuestion $resource,
        private readonly Collection $answers,
        private readonly array $authors,
    ) {
        parent::__construct($resource);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var CourseQuestion $question */
        $question = $this->resource;

        $questionAuthor = $this->authors[(int) $question->user_id] ?? null;

        return [
            'id' => $question->public_id,
            'title' => $question->title,
            'body' => $question->body,
            'status' => $question->status->value,
            'pinned' => $question->isPinned(),
            'pinned_at' => $question->pinned_at?->toIso8601String(),
            'lesson_timestamp_seconds' => $question->lesson_timestamp_seconds,
            'answers_count' => (int) $question->answers_count,
            'accepted_answer_id' => $question->acceptedAnswer?->public_id,
            'is_resolved' => $question->isResolved(),
            'author' => $questionAuthor === null ? null : [
                'id' => $questionAuthor->publicId,
                'name' => $questionAuthor->name,
            ],
            'created_at' => $question->created_at?->toIso8601String(),
            'updated_at' => $question->updated_at?->toIso8601String(),
            'answers' => $this->answers
                ->map(fn (QuestionAnswer $answer): array => (new AnswerResource(
                    $answer,
                    $this->authors[(int) $answer->user_id] ?? null,
                ))->toArray($request))
                ->all(),
        ];
    }
}
