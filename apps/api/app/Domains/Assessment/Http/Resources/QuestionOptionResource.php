<?php

namespace App\Domains\Assessment\Http\Resources;

use App\Platform\Shared\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * AUTHOR view of an option — includes the answer key. Never return this to a learner; use
 * LearnerOptionResource instead.
 */
class QuestionOptionResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->public_id,
            'label' => $this->resource->localized('label'),
            'value' => $this->resource->value,
            'is_correct' => $this->resource->is_correct,
            'group_index' => $this->resource->group_index,
            'feedback' => $this->resource->localized('feedback'),
            'position' => $this->resource->position,
        ];
    }
}
