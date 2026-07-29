<?php

namespace App\Domains\Authoring\Snapshots;

use App\Domains\Authoring\Enums\PublishState;
use App\Domains\Authoring\Exceptions\UnsupportedSnapshotSchemaException;
use App\Domains\Authoring\Models\Block;
use App\Domains\Authoring\Models\Lesson;
use App\Domains\Authoring\Models\Module;
use App\Domains\Authoring\Models\Section;

/**
 * P2/W03 - Deterministic capture + checksum of a course's authoring subtree.
 *
 * Guarantees:
 *  - stable ordering (position, then public_id) at every level
 *  - explicit whitelisted fields only (no hidden Eloquent serialization, no internal ids, no
 *    timestamps that would churn the checksum)
 *  - normalized enum values (backing string)
 *  - a deterministic SHA-256 checksum over a canonicalised (key-sorted) JSON encoding
 *  - a schema_version field so restoration can reject unsupported snapshots
 *
 * Blocks are captured only when the `authoring.blocks_enabled` flag is on — when off they are not
 * part of the active curriculum, so a snapshot omits them (feature-flag honoured).
 */
final class SnapshotSerializer
{
    public const SCHEMA_VERSION = 1;

    /** @return array<string, mixed> */
    public function capture(int $courseId): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'course_id' => $courseId,
            'modules' => $this->captureModules($courseId),
            'sections' => $this->captureSections($courseId),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function captureModules(int $courseId): array
    {
        return Module::query()
            ->where('course_id', $courseId)
            ->orderBy('position')->orderBy('public_id')
            ->get()
            ->map(fn (Module $m): array => [
                'public_id' => (string) $m->public_id,
                'parent_public_id' => $m->parent_id === null
                    ? null
                    : (string) (Module::withTrashed()->whereKey($m->parent_id)->value('public_id')),
                'title' => (string) $m->getAttribute('title'),
                'summary' => $m->getAttribute('summary') !== null ? (string) $m->getAttribute('summary') : null,
                'position' => (int) $m->position,
                'publish_state' => $this->stateValue($m->publish_state),
            ])
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function captureSections(int $courseId): array
    {
        $blocksEnabled = (bool) config('authoring.blocks_enabled', false);

        return Section::query()
            ->where('course_id', $courseId)
            ->orderBy('position')->orderBy('public_id')
            ->get()
            ->map(fn (Section $s): array => [
                'public_id' => (string) $s->getAttribute('public_id'),
                'title' => (string) $s->getAttribute('title'),
                'summary' => $s->getAttribute('summary') !== null ? (string) $s->getAttribute('summary') : null,
                'position' => (int) $s->getAttribute('position'),
                'publish_state' => $this->stateValue($s->getAttribute('publish_state')),
                'lessons' => $this->captureLessons((int) $s->id, $blocksEnabled),
            ])
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function captureLessons(int $sectionId, bool $blocksEnabled): array
    {
        return Lesson::query()
            ->where('section_id', $sectionId)
            ->orderBy('position')->orderBy('public_id')
            ->get()
            ->map(function (Lesson $l) use ($blocksEnabled): array {
                $prereqIds = $l->prerequisites()
                    ->orderBy('public_id')
                    ->pluck('public_id')
                    ->map(fn ($v) => (string) $v)
                    ->all();
                sort($prereqIds);

                $media = $l->media;

                return [
                    'public_id' => (string) $l->public_id,
                    'title' => (string) $l->title,
                    'type' => $l->type->value,
                    'content' => $l->content,
                    'position' => (int) $l->getAttribute('position'),
                    'publish_state' => $this->stateValue($l->publish_state),
                    'is_preview' => (bool) $l->getAttribute('is_preview'),
                    'assessment_id' => $l->assessment_id !== null ? (int) $l->assessment_id : null,
                    'media' => $media === null ? null : [
                        'mux_asset_id' => $media->getAttribute('mux_asset_id'),
                        'mux_playback_id' => $media->getAttribute('mux_playback_id'),
                        's3_key' => $media->getAttribute('s3_key'),
                        'mime_type' => $media->getAttribute('mime_type'),
                        'duration' => $media->getAttribute('duration') !== null ? (int) $media->getAttribute('duration') : null,
                        'filesize' => $media->getAttribute('filesize') !== null ? (int) $media->getAttribute('filesize') : null,
                    ],
                    'prerequisite_public_ids' => $prereqIds,
                    'blocks' => $blocksEnabled ? $this->captureBlocks((int) $l->id) : [],
                ];
            })
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function captureBlocks(int $lessonId): array
    {
        return Block::query()
            ->where('lesson_id', $lessonId)
            ->orderBy('position')->orderBy('public_id')
            ->get()
            ->map(fn (Block $b): array => [
                'public_id' => (string) $b->public_id,
                'family' => $b->family->value,
                'type' => $b->type->value,
                'payload' => $b->payload,
                'position' => (int) $b->position,
                'publish_state' => $this->stateValue($b->publish_state),
                'learning_object_id' => $b->learning_object_id !== null ? (int) $b->learning_object_id : null,
            ])
            ->all();
    }

    private function stateValue(mixed $state): string
    {
        return $state instanceof PublishState ? $state->value : (string) $state;
    }

    /**
     * Deterministic SHA-256 over a canonicalised snapshot. Associative arrays are key-sorted
     * recursively; list order is preserved (the serializer already produced it deterministically).
     *
     * @param  array<string, mixed>  $snapshot
     */
    public static function checksum(array $snapshot): string
    {
        $json = json_encode(self::canonical($snapshot), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return hash('sha256', (string) $json);
    }

    private static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(static fn ($v) => self::canonical($v), $value);
        }

        ksort($value);

        return array_map(static fn ($v) => self::canonical($v), $value);
    }

    /**
     * Validate a snapshot before it is used to restore. Fails safely on an unsupported schema.
     *
     * @param  array<string, mixed>  $snapshot
     */
    public static function validate(array $snapshot): void
    {
        $version = $snapshot['schema_version'] ?? null;

        if ($version !== self::SCHEMA_VERSION) {
            throw new UnsupportedSnapshotSchemaException(
                'Unsupported snapshot schema version.',
                ['expected' => self::SCHEMA_VERSION, 'given' => $version],
            );
        }

        if (! isset($snapshot['sections']) || ! is_array($snapshot['sections'])
            || ! isset($snapshot['modules']) || ! is_array($snapshot['modules'])) {
            throw new UnsupportedSnapshotSchemaException(
                'Snapshot payload is malformed.',
                ['schema_version' => $version],
            );
        }
    }

    /**
     * Cheap summary counts for version-history listing (stored in metadata at write time).
     *
     * @param  array<string, mixed>  $snapshot
     * @return array{modules:int, sections:int, lessons:int, blocks:int}
     */
    public static function counts(array $snapshot): array
    {
        $sections = is_array($snapshot['sections'] ?? null) ? $snapshot['sections'] : [];
        $lessons = 0;
        $blocks = 0;

        foreach ($sections as $section) {
            $sectionLessons = is_array($section['lessons'] ?? null) ? $section['lessons'] : [];
            $lessons += count($sectionLessons);
            foreach ($sectionLessons as $lesson) {
                $blocks += count(is_array($lesson['blocks'] ?? null) ? $lesson['blocks'] : []);
            }
        }

        return [
            'modules' => count(is_array($snapshot['modules'] ?? null) ? $snapshot['modules'] : []),
            'sections' => count($sections),
            'lessons' => $lessons,
            'blocks' => $blocks,
        ];
    }
}
