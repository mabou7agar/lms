<?php

namespace App\Domains\Catalog\Services;

use App\Domains\Catalog\Models\Category;
use App\Domains\Catalog\Models\Course;
use App\Domains\Catalog\Models\CourseLanguage;
use App\Domains\Catalog\Models\CourseLevel;
use App\Domains\Catalog\Models\CourseTag;
use App\Platform\Shared\Services\BaseService;
use App\Platform\Shared\Text\ArabicTextNormalizer;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Builds the public course listing query from filters (all keyed by public_id) with search,
 * featured ordering and pagination. Read-only; only ever returns published + public courses.
 */
class CourseSearchService extends BaseService
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginate(array $filters, int $perPage): LengthAwarePaginator
    {
        $query = Course::query()
            ->published()
            ->visible()
            ->with(['level', 'language', 'categories', 'tags', 'trainerLinks']);

        $this->applyCategory($query, $filters['category'] ?? null);
        $this->applyLevel($query, $filters['level'] ?? null);
        $this->applyLanguage($query, $filters['language'] ?? null);
        $this->applyTag($query, $filters['tag'] ?? null);
        $this->applyFeatured($query, $filters['featured'] ?? null);
        $this->applySearch($query, $filters['q'] ?? null);

        return $query
            ->orderByDesc('is_featured')
            ->orderByRaw('featured_at is null asc')
            ->orderByDesc('featured_at')
            ->orderByDesc('published_at')
            ->paginate($perPage)
            ->withQueryString();
    }

    private function applyCategory(Builder $query, ?string $publicId): void
    {
        if ($publicId) {
            $id = Category::where('public_id', $publicId)->value('id');
            $query->whereHas('categories', fn (Builder $q) => $q->whereKey($id));
        }
    }

    private function applyLevel(Builder $query, ?string $publicId): void
    {
        if ($publicId) {
            $query->where('level_id', CourseLevel::where('public_id', $publicId)->value('id'));
        }
    }

    private function applyLanguage(Builder $query, ?string $publicId): void
    {
        if ($publicId) {
            $query->where('language_id', CourseLanguage::where('public_id', $publicId)->value('id'));
        }
    }

    private function applyTag(Builder $query, ?string $publicId): void
    {
        if ($publicId) {
            $id = CourseTag::where('public_id', $publicId)->value('id');
            $query->whereHas('tags', fn (Builder $q) => $q->whereKey($id));
        }
    }

    private function applyFeatured(Builder $query, mixed $featured): void
    {
        if ($featured !== null && filter_var($featured, FILTER_VALIDATE_BOOLEAN)) {
            $query->featured();
        }
    }

    /**
     * Bilingual, Arabic-aware search over the folded `search_text` index (title/subtitle/description
     * across every locale). The query is folded through the same normaliser that builds the index,
     * so it matches regardless of locale, diacritics, alef/ya/ta-marbuta form, digit script or case.
     * LIKE metacharacters in the user's term are escaped, so "50%" searches for the literal text.
     */
    private function applySearch(Builder $query, ?string $term): void
    {
        $raw = is_string($term) ? trim($term) : '';

        if (strlen($raw) < (int) config('catalog.search.min_query_length', 2)) {
            return;
        }

        $needle = app(ArabicTextNormalizer::class)->normalize($raw);

        if ($needle === '') {
            return;
        }

        // Escape the LIKE wildcards (\ % _) so they are matched literally, not as patterns.
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $needle);

        $query->where('search_text', 'like', '%'.$escaped.'%');
    }
}
