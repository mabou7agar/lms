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
            // C1: raw locale maps so the builder can round-trip EN/AR authoring. The scalar
            // `title`/`summary` above stay the localized string-out for backward-compatible readers.
            'title_i18n' => $this->resource->title_i18n ?? [],
            'summary_i18n' => $this->resource->summary_i18n ?? [],
            'position' => $this->resource->position,
            'publish_state' => $this->resource->publish_state->value,
            // C3: optimistic-lock counter the builder echoes back as expected_version on edits.
            'lock_version' => $this->resource->lock_version,
            'lessons' => LessonResource::collection($this->whenLoaded('lessons')),
        ];
    }
}
