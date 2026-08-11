<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Search;

use App\Domains\Catalog\Models\Course;
use App\Platform\Shared\Enums\Visibility;
use App\Platform\Shared\Search\Contracts\IndexableContentPort;
use App\Platform\Shared\Search\Data\IndexableChunk;
use App\Platform\Shared\Search\Enums\SearchSourceType;
use App\Platform\Shared\Search\Enums\SearchVisibility;
use DateTimeInterface;

/**
 * Catalog's IndexableContentPort: exposes PUBLISHED + publicly-visible courses to the search index.
 * Imports only Catalog's own Course model + the Shared search contract (Deptrac: Catalog -> Shared).
 *
 * Only published, public courses are indexed, and only their marketing prose (title / subtitle /
 * description across locales) — never drafts, private/unlisted/hidden courses, pricing, or any
 * non-public field. One chunk per (field, locale) keeps each chunk a tight token set so the semantic
 * arm can retrieve a reordered paraphrase of a single field.
 */
final class CourseIndexableContentAdapter implements IndexableContentPort
{
    /** Prose fields indexed for a course: base scalar name + its {base}_i18n localized map. */
    private const FIELDS = ['title', 'subtitle', 'description'];

    public function sourceType(): string
    {
        return SearchSourceType::Course->value;
    }

    public function indexableIds(): array
    {
        return Course::query()
            ->published()
            ->visible()
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    public function chunksFor(int $id): array
    {
        $course = Course::query()->whereKey($id)->first();

        // Guard: only index a published, publicly-visible course. Anything else removes its rows.
        $visibility = $course?->getAttribute('visibility');
        if ($course === null || ! $course->isPublished()
            || ! ($visibility instanceof Visibility) || ! $visibility->isPublic()) {
            return [];
        }

        $organizationId = $this->intOrNull($course->getAttribute('organization_id'));
        $updatedAt = $course->getAttribute('updated_at');
        $version = $updatedAt instanceof DateTimeInterface ? $updatedAt->getTimestamp() : 1;
        $publicId = (string) $course->getAttribute('public_id');
        $title = $this->stringOrNull($course->getAttribute('title'));

        $chunks = [];
        foreach (self::FIELDS as $field) {
            foreach ($this->localizedValues($course, $field) as $locale => $text) {
                $chunks[] = new IndexableChunk(
                    embeddableType: 'course',
                    embeddableId: $id,
                    embeddablePublicId: $publicId,
                    organizationId: $organizationId,
                    locale: $locale,
                    sourceType: SearchSourceType::Course,
                    visibility: SearchVisibility::Public,
                    title: $title,
                    chunkText: $text,
                    version: $version,
                    chunkIndex: count($chunks),
                );
            }
        }

        return $chunks;
    }

    /**
     * Localized values for a field: every {base}_i18n locale plus the legacy scalar under 'en' when
     * no i18n entry already covers it. Empty strings are dropped.
     *
     * @return array<string, string>
     */
    private function localizedValues(Course $course, string $field): array
    {
        $values = [];

        $map = $course->getAttribute($field.'_i18n');
        if (is_array($map)) {
            foreach ($map as $locale => $value) {
                if (is_string($locale) && is_string($value) && trim($value) !== '') {
                    $values[$locale] = $value;
                }
            }
        }

        $scalar = $course->getAttribute($field);
        if (is_string($scalar) && trim($scalar) !== '' && ! isset($values['en'])) {
            $values['en'] = $scalar;
        }

        return $values;
    }

    private function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
