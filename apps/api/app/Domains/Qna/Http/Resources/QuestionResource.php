<?php

declare(strict_types=1);

namespace App\Domains\Qna\Http\Resources;

use App\Domains\Qna\Models\CourseQuestion;
use App\Platform\Identity\Contracts\Data\UserRef;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * List/summary shape of a question. The author is a boundary-safe {id: public_id, name} projection
 * only. The internal course_id / user_id / organization_id are never serialized — the accepted answer
 * is exposed by its public_id when the relation is loaded.
 */
class QuestionResource extends JsonResource
{
    public function __construct(CourseQuestion $resource, private readonly ?UserRef $author = null)
    {
        parent::__construct($resource);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var CourseQuestion $question */
        $question = $this->resource;

        return [
            'id' => $question->public_id,
            'title' => $question->title,
            'body' => $question->body,
            'status' => $question->status->value,
            'pinned' => $question->isPinned(),
            'pinned_at' => $question->pinned_at?->toIso8601String(),
            'lesson_timestamp_seconds' => $question->lesson_timestamp_seconds,
            'answers_count' => (int) $question->answers_count,
            'accepted_answer_id' => $this->whenLoaded('acceptedAnswer', fn () => $question->acceptedAnswer?->public_id),
            'is_resolved' => $question->isResolved(),
            'author' => $this->author === null ? null : [
                'id' => $this->author->publicId,
                'name' => $this->author->name,
            ],
            'created_at' => $question->created_at?->toIso8601String(),
            'updated_at' => $question->updated_at?->toIso8601String(),
        ];
    }
}
