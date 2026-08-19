<?php

namespace App\Platform\Homepage\Services;

use App\Domains\Catalog\Models\Category;
use App\Domains\Catalog\Models\Course;
use App\Domains\Live\Models\LiveSession;
use App\Platform\Homepage\Enums\BlockType;
use App\Platform\Homepage\Models\HomepageSection;
use App\Platform\Shared\Media\Contracts\PublicAssetUrlResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The SINGLE cross-context read path for the Homepage module. Blocks that reference live domain
 * entities — FeaturedCourses, FeaturedEvents, Categories — have those entities resolved here,
 * server-side, and returned as a plain `resolved` array the frontend renders directly. Centralizing
 * the coupling in one auditable class mirrors the SeoResolver / InstructorAnalyticsService precedent:
 * no other Homepage class touches Catalog / Live models.
 *
 * All reads eager-load their display relations to avoid N+1, and titles/names (plain string columns
 * on those aggregates) are normalized into bilingual { en, ar } bags so the frontend stays uniform.
 * Model fields are read via getAttribute() (as SeoResolver does), keeping the cross-context surface
 * to querying + attribute reads only.
 */
class HomepageContentResolver
{
    /**
     * Resolve the referenced entities for a block, or null when the block type references none.
     *
     * @param  array<string, mixed>  $content  the block's resolved content bag (published or draft)
     * @return array<string, mixed>|null
     */
    public function resolve(HomepageSection $section, array $content): ?array
    {
        return match ($section->type) {
            BlockType::FeaturedCourses => ['courses' => $this->courses($content)],
            BlockType::FeaturedEvents => ['events' => $this->events($content)],
            BlockType::Categories => ['categories' => $this->categories($content)],
            default => null,
        };
    }

    /**
     * Featured/published public courses. Honors an explicit `course_slugs` allow-list when present,
     * otherwise shows only the most-recently featured published courses. Capped by `limit`.
     *
     * @param  array<string, mixed>  $content
     * @return array<int, array<string, mixed>>
     */
    private function courses(array $content): array
    {
        $limit = $this->limit($content, 9, 12);
        $slugs = $this->stringList($content['course_slugs'] ?? null);

        $query = Course::query()->published()->visible()->with(['level', 'language']);

        if ($slugs !== []) {
            $query->whereIn('slug', $slugs);
        } else {
            $query->recentlyFeatured();
        }

        $out = [];
        foreach ($query->limit($limit)->get() as $course) {
            $level = $course->getAttribute('level');

            $out[] = [
                'id' => $course->getAttribute('public_id'),
                'title' => $this->bag($course->getAttribute('title')),
                'subtitle' => $this->bag($course->getAttribute('subtitle')),
                'slug' => $course->getAttribute('slug'),
                // P1: resolve a MediaAsset reference to a public URL (legacy path passes through).
                'thumbnail' => app(PublicAssetUrlResolver::class)->resolve($course->getAttribute('thumbnail_path')),
                'level' => $level instanceof Model ? $level->getAttribute('name') : null,
                'href' => '/courses/'.$course->getAttribute('public_id'),
            ];
        }

        return $out;
    }

    /**
     * Upcoming scheduled live sessions (events), soonest first. Capped by `limit`.
     *
     * @param  array<string, mixed>  $content
     * @return array<int, array<string, mixed>>
     */
    private function events(array $content): array
    {
        $limit = $this->limit($content, 4, 12);

        $out = [];
        foreach (LiveSession::query()->upcoming()->orderBy('starts_at')->limit($limit)->get() as $session) {
            $startsAt = $session->getAttribute('starts_at');

            $out[] = [
                'id' => $session->getAttribute('public_id'),
                'title' => $this->bag($session->getAttribute('title')),
                'description' => $this->bag($session->getAttribute('description')),
                'starts_at' => $startsAt instanceof Carbon ? $startsAt->toIso8601String() : null,
                'href' => '/events/'.$session->getAttribute('public_id'),
            ];
        }

        return $out;
    }

    /**
     * Active categories. Honors an explicit `category_slugs` allow-list when present, otherwise
     * falls back to active root categories by position. Capped by `limit`.
     *
     * @param  array<string, mixed>  $content
     * @return array<int, array<string, mixed>>
     */
    private function categories(array $content): array
    {
        $limit = $this->limit($content, 8, 24);
        $slugs = $this->stringList($content['category_slugs'] ?? null);

        $query = Category::query()->active()->orderBy('position');

        if ($slugs !== []) {
            $query->whereIn('slug', $slugs);
        } else {
            $query->roots();
        }

        $out = [];
        foreach ($query->limit($limit)->get() as $category) {
            $slug = $category->getAttribute('slug');

            $out[] = [
                'id' => $category->getAttribute('public_id'),
                'name' => $this->bag($category->getAttribute('name')),
                'description' => $this->bag($category->getAttribute('description')),
                'slug' => $slug,
                'href' => '/categories/'.$slug,
            ];
        }

        return $out;
    }

    /**
     * Clamp a content `limit` into a sane range.
     *
     * @param  array<string, mixed>  $content
     */
    private function limit(array $content, int $default, int $max): int
    {
        $value = $content['limit'] ?? $default;
        $value = is_numeric($value) ? (int) $value : $default;

        return max(1, min($value, $max));
    }

    /**
     * Normalize a value that may be an array of strings into a clean string list.
     *
     * @return array<int, string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(fn ($v) => is_string($v) ? trim($v) : '', $value),
            fn (string $v) => $v !== '',
        ));
    }

    /**
     * Normalize a plain string or existing bilingual bag into a { en, ar } bag (en value mirrored to
     * ar when only one is present). Null/empty yields null.
     *
     * @return array<string, string>|null
     */
    private function bag(mixed $value): ?array
    {
        if (is_array($value)) {
            $en = isset($value['en']) && is_string($value['en']) ? $value['en'] : '';
            $ar = isset($value['ar']) && is_string($value['ar']) ? $value['ar'] : $en;

            return $en === '' && $ar === '' ? null : ['en' => $en, 'ar' => $ar];
        }

        if (is_string($value) && $value !== '') {
            return ['en' => $value, 'ar' => $value];
        }

        return null;
    }
}
