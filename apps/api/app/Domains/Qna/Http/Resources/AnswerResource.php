<?php

declare(strict_types=1);

namespace App\Domains\Qna\Http\Resources;

use App\Domains\Qna\Models\QuestionAnswer;
use App\Platform\Identity\Contracts\Data\UserRef;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The wire shape of an answer. The author is exposed ONLY as a boundary-safe {id: public_id, name}
 * projection — never the internal user id, email, or any other PII. Internal ids and the tenant
 * column are never serialized.
 */
class AnswerResource extends JsonResource
{
    public function __construct(QuestionAnswer $resource, private readonly ?UserRef $author = null)
    {
        parent::__construct($resource);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var QuestionAnswer $answer */
        $answer = $this->resource;

        return [
            'id' => $answer->public_id,
            'body' => $answer->body,
            'is_instructor' => (bool) $answer->is_instructor,
            'accepted' => (bool) $answer->accepted,
            // "Official" is the course saying this is correct; "accepted" is the asker saying it
            // solved their problem. Both, either or neither can be true.
            'is_official' => (bool) $answer->is_official,
            'author' => $this->author === null ? null : [
                'id' => $this->author->publicId,
                'name' => $this->author->name,
            ],
            'created_at' => $answer->created_at?->toIso8601String(),
            'updated_at' => $answer->updated_at?->toIso8601String(),
        ];
    }
}
