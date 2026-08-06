<?php

namespace App\Domains\Authoring\Http\Resources;

use App\Domains\Authoring\Models\Section;
use App\Platform\Shared\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * @property Section $resource
 */
class SectionResource extends BaseResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->public_id,
            'title' => $this->resource->localized('title'),
            'summary' => $this->resource->localized('summary'),
            'position' => $this->resource->position,
            'publish_state' => $this->resource->publish_state->value,
            'lessons' => LessonResource::collection($this->whenLoaded('lessons')),
        ];
    }
}
