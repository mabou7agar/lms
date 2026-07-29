<?php

namespace App\Domains\Authoring\Snapshots;

use App\Domains\Authoring\Models\Block;
use App\Domains\Authoring\Models\Lesson;
use App\Domains\Authoring\Models\LessonMedia;
use App\Domains\Authoring\Models\Module;
use App\Domains\Authoring\Models\Section;

/**
 * P2/W03 - Rebuilds a course's authoring subtree from a snapshot. MUST be called inside a
 * transaction (the versioning service owns that boundary), so a mid-restore failure rolls the whole
 * draft back untouched.
 *
 * `$regenerateIds` = false  -> restore into the SAME course, preserving public_ids (old rows are
 *                              hard-deleted first, so there is no collision). External identity is
 *                              kept stable.
 * `$regenerateIds` = true   -> fork into ANOTHER course: every entity gets a fresh public_id, all
 *                              rows carry only the destination course_id, and per-course references
 *                              (assessment_id) are dropped so no source foreign key leaks across.
 *
 * Rows are written with forceFill(): the attribute map is explicit and whitelisted here and casts
 * still apply on save, so restoration stays type-clean without leaning on per-model annotations.
 */
final class SnapshotRestorer
{
    /** @param array<string, mixed> $snapshot */
    public function restore(int $courseId, array $snapshot, bool $regenerateIds): void
    {
        SnapshotSerializer::validate($snapshot);

        // Clear the current subtree. Hard deletes so DB cascades remove lessons -> blocks/media/
        // prerequisites and public_ids are free to be reused on the same-course restore path.
        Section::query()->where('course_id', $courseId)->forceDelete();
        Module::query()->where('course_id', $courseId)->forceDelete();

        $this->restoreModules($courseId, $this->list($snapshot, 'modules'), $regenerateIds);
        $this->restoreSections($courseId, $this->list($snapshot, 'sections'), $regenerateIds);
    }

    /** @param list<array<string, mixed>> $modules */
    private function restoreModules(int $courseId, array $modules, bool $regenerateIds): void
    {
        // Map old public_id -> new internal id, resolving parents before children in repeated passes.
        $created = [];
        $pending = $modules;

        while ($pending !== []) {
            $progressed = false;

            foreach ($pending as $key => $module) {
                $parentPublicId = $module['parent_public_id'] ?? null;

                if ($parentPublicId !== null && ! isset($created[$parentPublicId])) {
                    continue; // parent not created yet
                }

                $parentId = $parentPublicId !== null ? $created[$parentPublicId] : null;
                $row = $this->makeModule($courseId, $module, $parentId, $regenerateIds);

                if (isset($module['public_id'])) {
                    $created[(string) $module['public_id']] = (int) $row->id;
                }
                unset($pending[$key]);
                $progressed = true;
            }

            // A dangling/cyclic parent reference can never resolve — create the remainder as roots
            // rather than looping forever.
            if (! $progressed) {
                foreach ($pending as $key => $module) {
                    $this->makeModule($courseId, $module, null, $regenerateIds);
                    unset($pending[$key]);
                }
                break;
            }
        }
    }

    /** @param array<string, mixed> $module */
    private function makeModule(int $courseId, array $module, ?int $parentId, bool $regenerateIds): Module
    {
        $attributes = [
            'course_id' => $courseId,
            'parent_id' => $parentId,
            'title' => (string) $module['title'],
            'summary' => $module['summary'] ?? null,
            'position' => (int) ($module['position'] ?? 0),
            'publish_state' => (string) $module['publish_state'],
        ];

        if (! $regenerateIds && isset($module['public_id'])) {
            $attributes['public_id'] = (string) $module['public_id'];
        }

        $row = (new Module)->forceFill($attributes);
        $row->save();

        return $row;
    }

    /** @param list<array<string, mixed>> $sections */
    private function restoreSections(int $courseId, array $sections, bool $regenerateIds): void
    {
        /** @var array<string, int> $lessonMap old lesson public_id => new lesson id */
        $lessonMap = [];
        /** @var list<array{lesson:int, prereqs:list<string>}> $prereqLinks */
        $prereqLinks = [];
        $blocksEnabled = (bool) config('authoring.blocks_enabled', false);

        foreach ($sections as $section) {
            $sectionAttributes = [
                'course_id' => $courseId,
                'title' => (string) $section['title'],
                'summary' => $section['summary'] ?? null,
                'position' => (int) ($section['position'] ?? 0),
                'publish_state' => (string) $section['publish_state'],
            ];
            if (! $regenerateIds && isset($section['public_id'])) {
                $sectionAttributes['public_id'] = (string) $section['public_id'];
            }
            $sectionRow = (new Section)->forceFill($sectionAttributes);
            $sectionRow->save();
            $sectionId = (int) $sectionRow->id;

            foreach ($this->list($section, 'lessons') as $lesson) {
                $lessonAttributes = [
                    'section_id' => $sectionId,
                    'title' => (string) $lesson['title'],
                    'type' => (string) $lesson['type'],
                    'content' => $lesson['content'] ?? null,
                    'position' => (int) ($lesson['position'] ?? 0),
                    'publish_state' => (string) $lesson['publish_state'],
                    'is_preview' => (bool) ($lesson['is_preview'] ?? false),
                    // assessment_id is a per-course reference; drop it when forking to another course.
                    'assessment_id' => $regenerateIds ? null : ($lesson['assessment_id'] ?? null),
                ];
                if (! $regenerateIds && isset($lesson['public_id'])) {
                    $lessonAttributes['public_id'] = (string) $lesson['public_id'];
                }
                $lessonRow = (new Lesson)->forceFill($lessonAttributes);
                $lessonRow->save();
                $lessonId = (int) $lessonRow->id;

                if (isset($lesson['public_id'])) {
                    $lessonMap[(string) $lesson['public_id']] = $lessonId;
                }

                $prereqs = $this->stringList($lesson, 'prerequisite_public_ids');
                if ($prereqs !== []) {
                    $prereqLinks[] = ['lesson' => $lessonId, 'prereqs' => $prereqs];
                }

                $this->restoreMedia($lessonId, $lesson['media'] ?? null);

                if ($blocksEnabled) {
                    $this->restoreBlocks($lessonId, $this->list($lesson, 'blocks'), $regenerateIds);
                }
            }
        }

        // Second pass: wire prerequisites now that every lesson id is known. A prerequisite whose
        // target is not part of this snapshot is skipped.
        foreach ($prereqLinks as $link) {
            $targets = [];
            foreach ($link['prereqs'] as $publicId) {
                if (isset($lessonMap[$publicId])) {
                    $targets[] = $lessonMap[$publicId];
                }
            }
            if ($targets !== []) {
                Lesson::query()->whereKey($link['lesson'])->first()?->prerequisites()->sync($targets);
            }
        }
    }

    /** @param array<string, mixed>|null $media */
    private function restoreMedia(int $lessonId, ?array $media): void
    {
        if ($media === null) {
            return;
        }

        $row = (new LessonMedia)->forceFill([
            'lesson_id' => $lessonId,
            'mux_asset_id' => $media['mux_asset_id'] ?? null,
            'mux_playback_id' => $media['mux_playback_id'] ?? null,
            's3_key' => $media['s3_key'] ?? null,
            'mime_type' => $media['mime_type'] ?? null,
            'duration' => isset($media['duration']) ? (int) $media['duration'] : null,
            'filesize' => isset($media['filesize']) ? (int) $media['filesize'] : null,
        ]);
        $row->save();
    }

    /** @param list<array<string, mixed>> $blocks */
    private function restoreBlocks(int $lessonId, array $blocks, bool $regenerateIds): void
    {
        foreach ($blocks as $block) {
            $attributes = [
                'lesson_id' => $lessonId,
                'type' => (string) $block['type'],       // family is derived on save
                'payload' => $block['payload'] ?? null,
                'position' => (int) ($block['position'] ?? 0),
                'publish_state' => (string) $block['publish_state'],
                'learning_object_id' => $block['learning_object_id'] ?? null,
            ];
            if (! $regenerateIds && isset($block['public_id'])) {
                $attributes['public_id'] = (string) $block['public_id'];
            }
            $row = (new Block)->forceFill($attributes);
            $row->save();
        }
    }

    /**
     * @param  array<string, mixed>  $source
     * @return list<array<string, mixed>>
     */
    private function list(array $source, string $key): array
    {
        $value = $source[$key] ?? [];

        return is_array($value) ? array_values($value) : [];
    }

    /**
     * @param  array<string, mixed>  $source
     * @return list<string>
     */
    private function stringList(array $source, string $key): array
    {
        $value = $source[$key] ?? [];

        return is_array($value) ? array_values(array_map(static fn ($v) => (string) $v, $value)) : [];
    }
}
