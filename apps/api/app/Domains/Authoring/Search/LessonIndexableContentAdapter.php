<?php

declare(strict_types=1);

namespace App\Domains\Authoring\Search;

use App\Domains\Authoring\Enums\PublishState;
use App\Domains\Authoring\Models\Block;
use App\Domains\Authoring\Models\Lesson;
use App\Domains\Authoring\Models\Section;
use App\Platform\Shared\Search\Contracts\IndexableContentPort;
use App\Platform\Shared\Search\Data\IndexableChunk;
use App\Platform\Shared\Search\Enums\SearchSourceType;
use App\Platform\Shared\Search\Enums\SearchVisibility;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

/**
 * Authoring's IndexableContentPort: exposes PUBLISHED lesson text (title + published text blocks) to
 * the search index as AUTHENTICATED-audience knowledge. Imports only Authoring's own models + the
 * Shared contract (Deptrac: Authoring -> Shared). The owning course's tenant + published state are
 * resolved by a raw `courses` lookup, so no Catalog model is imported.
 *
 * Only published lessons inside a published section of a PUBLISHED course are indexed. Drafts, hidden
 * lessons and unpublished blocks are never indexed. Lesson knowledge is authenticated-only — it is
 * never returned to anonymous catalogue search.
 */
final class LessonIndexableContentAdapter implements IndexableContentPort
{
    public function sourceType(): string
    {
        return SearchSourceType::Lesson->value;
    }

    public function indexableIds(): array
    {
        // Columns are fully qualified: both `lessons` and `course_sections` have a `publish_state`,
        // so the unqualified scope would be ambiguous under the join.
        return Lesson::query()
            ->join('course_sections', 'course_sections.id', '=', 'lessons.section_id')
            ->join('courses', 'courses.id', '=', 'course_sections.course_id')
            ->where('lessons.publish_state', PublishState::Published->value)
            ->where('course_sections.publish_state', PublishState::Published->value)
            ->where('courses.status', 'published')
            ->orderBy('lessons.id')
            ->pluck('lessons.id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    public function chunksFor(int $id): array
    {
        $lesson = Lesson::query()->with('section')->whereKey($id)->first();

        if ($lesson === null || ! $lesson->isPublished()) {
            return [];
        }

        $section = $lesson->getRelation('section');
        if (! $section instanceof Section || $section->getAttribute('publish_state') !== PublishState::Published) {
            return [];
        }

        $courseId = $section->getAttribute('course_id');
        if (! is_numeric($courseId)) {
            return [];
        }

        // Resolve the owning course's tenant + published state WITHOUT importing the Catalog model.
        $course = DB::table('courses')->where('id', $courseId)->first(['organization_id', 'status']);
        if ($course === null || $course->status !== 'published') {
            return [];
        }

        $text = $this->lessonText($lesson);
        if ($text === '') {
            return [];
        }

        $updatedAt = $lesson->getAttribute('updated_at');

        return [new IndexableChunk(
            embeddableType: 'lesson',
            embeddableId: $id,
            embeddablePublicId: (string) $lesson->getAttribute('public_id'),
            organizationId: is_numeric($course->organization_id) ? (int) $course->organization_id : null,
            locale: '*',
            sourceType: SearchSourceType::Lesson,
            visibility: SearchVisibility::Authenticated,
            title: $this->stringOrNull($lesson->getAttribute('title')),
            chunkText: $text,
            version: $updatedAt instanceof DateTimeInterface ? $updatedAt->getTimestamp() : 1,
            chunkIndex: 0,
        )];
    }

    /** Lesson title (all locales) + flattened text of its published blocks, as one folded chunk. */
    private function lessonText(Lesson $lesson): string
    {
        $parts = $this->flattenStrings($lesson->getAttribute('title'));
        $parts = array_merge($parts, $this->flattenStrings($lesson->getAttribute('title_i18n')));

        $blocks = Block::query()
            ->where('lesson_id', $lesson->getKey())
            ->published()
            ->orderBy('position')
            ->get();

        foreach ($blocks as $block) {
            $parts = array_merge(
                $parts,
                $this->flattenStrings($block->getAttribute('content_i18n')),
                $this->flattenStrings($block->getAttribute('payload')),
            );
        }

        return trim(implode(' ', array_filter($parts, static fn (string $p): bool => trim($p) !== '')));
    }

    /**
     * Collect every string leaf from a scalar/array structure (block payloads are nested maps). Keeps
     * only human text; ignores keys and non-strings.
     *
     * @return list<string>
     */
    private function flattenStrings(mixed $value): array
    {
        if (is_string($value)) {
            return trim($value) !== '' ? [$value] : [];
        }

        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            foreach ($this->flattenStrings($item) as $leaf) {
                $out[] = $leaf;
            }
        }

        return $out;
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
