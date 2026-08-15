<?php

namespace App\Domains\Catalog\Http\Resources;

use App\Domains\Catalog\Models\Course;
use App\Domains\Catalog\Models\CourseTag;
use App\Platform\Identity\Contracts\UserLookupPort;
use App\Platform\Shared\Media\Contracts\PublicAssetUrlResolver;
use App\Platform\Shared\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * Full course detail (media-safe: no internal ids or storage keys beyond the public thumbnail).
 *
 * P1: `thumbnail_path` may hold a MediaAsset public_id reference (chosen via the MediaPicker) or a
 * legacy URL/path. It is resolved to a public URL through PublicAssetUrlResolver — a PUBLIC asset
 * yields a stable URL, a legacy value passes through unchanged, and a private/missing asset yields
 * null (never a raw reference or storage key). The field name is unchanged.
 *
 * @property Course $resource
 */
class CourseResource extends BaseResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->public_id,
            'title' => $this->resource->localized('title'),
            'slug' => $this->resource->slug,
            'subtitle' => $this->resource->localized('subtitle'),
            'description' => $this->resource->localized('description'),
            'status' => $this->resource->status->value,
            'visibility' => $this->resource->visibility->value,
            'is_featured' => $this->resource->is_featured,
            'thumbnail_path' => app(PublicAssetUrlResolver::class)->resolve($this->resource->thumbnail_path),
            // Promo video: same media-safe resolution as thumbnail_path (public_id -> URL, an external
            // http(s) trailer URL — YouTube/Vimeo/etc. — is a legacy value and passes through UNCHANGED,
            // private/missing -> null). Never a raw reference or storage key. Exposed as `trailer_path`
            // (mirrors the DB column + `thumbnail_path`); `trailer` is kept as a back-compat alias.
            'trailer_path' => $trailer = app(PublicAssetUrlResolver::class)->resolve($this->resource->trailer_path),
            'trailer' => $trailer,
            'duration_minutes' => $this->resource->duration_minutes,
            // Marketing lists, resolved to the request locale (never the raw {en,ar} map).
            'learning_objectives' => $this->localizedList('learning_objectives'),
            'requirements' => $this->localizedList('requirements'),
            'target_audience' => $this->localizedList('target_audience'),
            'seo' => $this->resource->seo,
            'level' => $this->whenLoaded('level', fn () => $this->resource->level ? [
                'id' => $this->resource->level->public_id,
                'name' => $this->resource->level->localized('name'),
            ] : null),
            'language' => $this->whenLoaded('language', fn () => $this->resource->language ? [
                'id' => $this->resource->language->public_id,
                'name' => $this->resource->language->localized('name'),
                'code' => $this->resource->language->code,
            ] : null),
            'categories' => CategoryResource::collection($this->whenLoaded('categories')),
            'tags' => $this->whenLoaded('tags', fn () => $this->resource->tags->map(fn (CourseTag $t) => [
                'id' => $t->public_id, 'name' => $t->localized('name'), 'slug' => $t->slug,
            ])->values()),
            'trainers' => $this->whenLoaded('trainerLinks', fn () => TrainerResource::collection(
                array_values(app(UserLookupPort::class)->refsByIds($this->resource->trainerLinks->pluck('user_id')->all()))
            )),
            'related' => CourseListResource::collection($this->whenLoaded('related')),
            'published_at' => $this->resource->published_at?->toIso8601String(),
        ];
    }

    /**
     * Resolve a localized marketing LIST attribute ({locale => [items...]}) to the request locale as a
     * clean list of strings. Returns [] when absent, so the raw {en,ar} map is never exposed.
     *
     * @return list<string>
     */
    private function localizedList(string $base): array
    {
        $value = $this->resource->localized($base);

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_map(static fn ($item): string => (string) $item, $value));
    }
}
