<?php

namespace App\Domains\Catalog\Http\Resources;

use App\Domains\Catalog\Models\Course;
use App\Platform\Shared\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * Compact course representation for listings.
 *
 * @property Course $resource
 */
class CourseListResource extends BaseResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->public_id,
            'title' => $this->resource->localized('title'),
            'slug' => $this->resource->slug,
            'subtitle' => $this->resource->localized('subtitle'),
            'thumbnail_path' => $this->resource->thumbnail_path,
            'is_featured' => $this->resource->is_featured,
            'level' => $this->whenLoaded('level', fn () => $this->resource->level?->localized('name')),
            'language' => $this->whenLoaded('language', fn () => $this->resource->language?->localized('name')),
            'published_at' => $this->resource->published_at?->toIso8601String(),
        ];
    }
}
