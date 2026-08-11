<?php

declare(strict_types=1);

namespace App\Platform\Search\Models;

use App\Platform\Search\Data\VectorQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A single indexed content chunk (the portable vector store's row). Deliberately NOT tenant-scoped
 * by a global scope: the owning organization_id is set EXPLICITLY from the source chunk at ingestion
 * time (a global course must keep organization_id NULL even if a tenant is active during backfill),
 * and every read applies the tenant/visibility/locale pre-filter EXPLICITLY via {@see scopeForQuery()}.
 * This keeps the index a precise projection rather than something a stray context could re-stamp.
 *
 * @property int $id
 * @property string $embeddable_type
 * @property int $embeddable_id
 * @property string|null $embeddable_public_id
 * @property int|null $organization_id
 * @property int|null $course_id
 * @property string $locale
 * @property string $source_type
 * @property string $visibility
 * @property int $chunk_index
 * @property string|null $title
 * @property string $chunk_text
 * @property list<float> $embedding
 * @property int $dims
 * @property string $model
 * @property int $version
 */
class ContentEmbedding extends Model
{
    protected $table = 'content_embeddings';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'embedding' => 'array',
            'dims' => 'integer',
            'version' => 'integer',
            'chunk_index' => 'integer',
            'organization_id' => 'integer',
            'embeddable_id' => 'integer',
            'course_id' => 'integer',
        ];
    }

    /**
     * Apply the mandatory tenant + visibility + locale + source-type pre-filter. This is the single
     * choke point that enforces isolation for BOTH the semantic and keyword arms:
     *   - tenant:      global rows (organization_id IS NULL) OR the active tenant's own rows — never
     *                  another tenant's private rows;
     *   - visibility:  only the audiences the caller is allowed to see (public search never passes
     *                  'authenticated' or 'private');
     *   - locale:      the requested locales plus the language-agnostic '*' chunks;
     *   - source_type: the requested content kinds (course / lesson / qna);
     *   - course:      when set, ONLY chunks belonging to that course (its own course chunk plus its
     *                  lessons and accepted Q&A). This is what confines the RAG tutor/copilot to a
     *                  single course so an answer can never be grounded in another course's content.
     *
     * @param  Builder<ContentEmbedding>  $query
     * @return Builder<ContentEmbedding>
     */
    public function scopeForQuery(Builder $query, VectorQuery $q): Builder
    {
        $query->where(function (Builder $w) use ($q): void {
            $w->whereNull('organization_id');
            if ($q->organizationId !== null) {
                $w->orWhere('organization_id', $q->organizationId);
            }
        });

        if ($q->courseId !== null) {
            $query->where('course_id', $q->courseId);
        }

        $query->whereIn('visibility', $q->visibilities !== [] ? $q->visibilities : ['public']);

        if ($q->sourceTypes !== []) {
            $query->whereIn('source_type', $q->sourceTypes);
        }

        if ($q->locales !== []) {
            $locales = array_values(array_unique([...$q->locales, '*']));
            $query->whereIn('locale', $locales);
        }

        return $query;
    }
}
