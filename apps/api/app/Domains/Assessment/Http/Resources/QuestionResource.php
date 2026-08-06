<?php

namespace App\Domains\Assessment\Http\Resources;

use App\Platform\Shared\Resources\BaseResource;
use Illuminate\Http\Request;

/** AUTHOR view of a question, including its full answer key. Admin/instructor endpoints only. */
class QuestionResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->public_id,
            'type' => $this->resource->type->value,
            'prompt' => $this->resource->localized('prompt'),
            'config' => $this->resource->config,
            'explanation' => $this->resource->localized('explanation'),
            'hint' => $this->resource->localized('hint'),
            'points' => (float) $this->resource->points,
            'negative_points' => (float) $this->resource->negative_points,
            'difficulty' => $this->resource->difficulty?->value,
            'position' => $this->resource->position,
            'options' => QuestionOptionResource::collection($this->whenLoaded('options')),
        ];
    }
}
