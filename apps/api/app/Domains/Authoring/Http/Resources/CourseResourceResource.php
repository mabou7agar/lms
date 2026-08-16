<?php

declare(strict_types=1);

namespace App\Domains\Authoring\Http\Resources;

use App\Domains\Authoring\Models\CourseResource;
use App\Platform\Shared\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * The wire shape of a course file.
 *
 * Carries no storage key, no provider id, and no media asset id — a client learns that a file exists
 * and what it is called, and gets bytes only by asking the download endpoint, which re-checks
 * entitlement and mints a short-lived signed URL. `size_bytes` and `mime_type` are included because
 * a learner deciding whether to tap a download on mobile data deserves to know what they are in for.
 *
 * @property CourseResource $resource
 */
class CourseResourceResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->public_id,
            'title' => $this->resource->title,
            'description' => $this->resource->description,
            'visibility' => $this->resource->visibility->value,
            'downloadable' => (bool) $this->resource->downloadable,
            'is_preview' => $this->resource->isPreview(),
            'scope' => $this->resource->isCourseLevel() ? 'course' : 'lesson',
            'position' => (int) $this->resource->position,
            'file' => [
                'mime_type' => $this->resource->getAttribute('mime_type'),
                'size_bytes' => $this->resource->getAttribute('size_bytes'),
            ],
            'created_at' => $this->resource->created_at?->toIso8601String(),
        ];
    }
}
