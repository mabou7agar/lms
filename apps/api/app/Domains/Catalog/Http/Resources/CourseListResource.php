<?php

namespace App\Domains\Catalog\Http\Resources;

use App\Domains\Catalog\Models\Course;
use App\Platform\Shared\Media\Contracts\PublicAssetUrlResolver;
use App\Platform\Shared\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * Compact course representation for listings. `thumbnail_path` is resolved to a public URL (P1):
 * a MediaAsset reference becomes a PUBLIC stable URL (or null when not public/missing), a legacy
 * value passes through unchanged. Field name unchanged.
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
            'thumbnail_path' => app(PublicAssetUrlResolver::class)->resolve($this->resource->thumbnail_path),
            // Trainers as boundary-safe refs (attached in CourseController::attachTrainerRefs). avatar_path
            // resolves to a public URL; empty when the listing path didn't attach trainers. Lets a course
            // card / cover show instructor avatars.
            'trainers' => collect($this->resource->trainer_refs ?? [])->map(fn ($ref): array => [
                'id' => $ref->publicId,
                'name' => $ref->name,
                'avatar_path' => app(PublicAssetUrlResolver::class)->resolve($ref->avatarPath),
            ])->all(),
            'is_featured' => $this->resource->is_featured,
            'level' => $this->whenLoaded('level', fn () => $this->resource->level?->localized('name')),
            'language' => $this->whenLoaded('language', fn () => $this->resource->language?->localized('name')),
            'published_at' => $this->resource->published_at?->toIso8601String(),
        ];
    }
}
