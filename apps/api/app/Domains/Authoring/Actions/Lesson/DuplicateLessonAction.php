<?php

namespace App\Domains\Authoring\Actions\Lesson;

use App\Domains\Authoring\Enums\PublishState;
use App\Domains\Authoring\Models\Lesson;
use App\Domains\Authoring\Models\LessonMedia;
use App\Domains\Authoring\Models\Section;
use App\Platform\Shared\Actions\BaseAction;

/**
 * Deep-copies a lesson within its own section: a fresh public_id, the full localized title map, the
 * content payload, type, media metadata row, and settings. The copy is appended at the end of the
 * section (position = max + 1) and reset to Draft — a duplicate is never published on creation.
 *
 * Copy semantics mirror the SnapshotRestorer's whitelisted field copy (nothing is serialized
 * implicitly), and the append reuses the CreateLessonAction convention. No content version is
 * snapshotted here, exactly as the create/update actions record none — versions are captured on
 * demand by ContentVersioningService and a later snapshot naturally includes the copied rows.
 */
class DuplicateLessonAction extends BaseAction
{
    public function execute(Lesson $lesson): Lesson
    {
        return $this->transaction(function () use ($lesson): Lesson {
            $sectionId = (int) $lesson->section_id;

            // Serialize concurrent appends into the same section. Postgres cannot apply FOR UPDATE to
            // an aggregate (max(position)), so the parent section row is locked instead — consistent
            // with the reorder actions owning their transaction boundary.
            Section::whereKey($sectionId)->lockForUpdate()->first();

            $position = (int) Lesson::where('section_id', $sectionId)->max('position') + 1;

            $copy = $this->copyLesson($lesson, $sectionId, $position);

            // A within-section duplicate stays in the same course, so the source prerequisites (all
            // same-course by construction) remain valid references and introduce no cycle: the fresh
            // lesson is a leaf nothing depends on yet.
            $prerequisiteIds = $lesson->prerequisites()->pluck('lessons.id')->all();
            if ($prerequisiteIds !== []) {
                $copy->prerequisites()->sync($prerequisiteIds);
            }

            return $copy->load('media', 'prerequisites');
        });
    }

    /**
     * Copy a single lesson row (and its media metadata) into a section at a given position, in Draft.
     * Prerequisites are the caller's responsibility so a whole-section copy can remap them in one pass.
     */
    public function copyLesson(Lesson $source, int $sectionId, int $position): Lesson
    {
        $copy = Lesson::create([
            'section_id' => $sectionId,
            'title' => $source->getAttribute('title'),
            'title_i18n' => $source->getAttribute('title_i18n'),
            'type' => $source->type->value,
            'content' => $source->content,
            // Same-course reference stays valid; only a cross-course fork drops it.
            'assessment_id' => $source->assessment_id,
            'position' => $position,
            'is_preview' => (bool) $source->getAttribute('is_preview'),
            'publish_state' => PublishState::Draft->value,
        ]);

        $media = $source->media;
        if ($media !== null) {
            LessonMedia::create([
                'lesson_id' => $copy->id,
                'mux_asset_id' => $media->getAttribute('mux_asset_id'),
                'mux_playback_id' => $media->getAttribute('mux_playback_id'),
                's3_key' => $media->getAttribute('s3_key'),
                'mime_type' => $media->getAttribute('mime_type'),
                'duration' => $media->getAttribute('duration'),
                'filesize' => $media->getAttribute('filesize'),
            ]);
        }

        return $copy;
    }
}
