<?php

namespace App\Domains\Authoring\Actions\Section;

use App\Domains\Authoring\Actions\Lesson\DuplicateLessonAction;
use App\Domains\Authoring\Enums\PublishState;
use App\Domains\Authoring\Models\Lesson;
use App\Domains\Authoring\Models\Section;
use App\Platform\Shared\Actions\BaseAction;
use Illuminate\Support\Facades\DB;

/**
 * Deep-copies a section AND every lesson beneath it, appended at the end of the course in Draft.
 * Each lesson is copied through DuplicateLessonAction's shared copy semantics (full localized title
 * map, content, type, media, settings, fresh public_id) so there is a single source of truth for
 * what a lesson copy means.
 *
 * Prerequisites are copied with intra-section remapping: an edge that pointed at another lesson in
 * the same section is repointed at that lesson's copy, while an edge pointing elsewhere in the
 * course (still same-course, still valid) is preserved against the original. The copied subgraph is
 * acyclic by construction — it clones an already-acyclic graph and adds no back-edges.
 *
 * No content version is snapshotted here, matching the create/update actions; ContentVersioning
 * captures snapshots on demand and a later snapshot naturally includes the copied rows.
 */
class DuplicateSectionAction extends BaseAction
{
    public function __construct(private readonly DuplicateLessonAction $lessons) {}

    public function execute(Section $section): Section
    {
        return $this->transaction(function () use ($section): Section {
            $courseId = (int) $section->course_id;

            // Serialize concurrent appends into the same course (see DuplicateLessonAction for why the
            // parent row is locked rather than the aggregate). Locked via the query builder so this
            // Authoring action keeps no cross-domain dependency on the Catalog Course model.
            DB::table('courses')->where('id', $courseId)->lockForUpdate()->first();

            $position = (int) Section::where('course_id', $courseId)->max('position') + 1;

            $copy = Section::create([
                'course_id' => $courseId,
                'title' => $section->getAttribute('title'),
                'title_i18n' => $section->getAttribute('title_i18n'),
                'summary' => $section->getAttribute('summary'),
                'summary_i18n' => $section->getAttribute('summary_i18n'),
                'position' => $position,
                'publish_state' => PublishState::Draft->value,
            ]);

            /** @var array<int, int> $idMap original lesson id => copied lesson id */
            $idMap = [];
            /** @var array<int, array<int, int>> $prerequisites copied lesson id => original prerequisite ids */
            $prerequisites = [];

            foreach ($section->lessons()->get() as $original) {
                $copiedLesson = $this->lessons->copyLesson($original, (int) $copy->id, (int) $original->getAttribute('position'));
                $idMap[(int) $original->id] = (int) $copiedLesson->id;

                $prerequisiteIds = $original->prerequisites()->pluck('lessons.id')->all();
                if ($prerequisiteIds !== []) {
                    $prerequisites[(int) $copiedLesson->id] = $prerequisiteIds;
                }
            }

            // Second pass once every copy exists: remap intra-section edges to the copies, keep
            // out-of-section (same-course) edges pointing at the originals.
            foreach ($prerequisites as $copiedLessonId => $prerequisiteIds) {
                $targets = array_map(static fn (int $id): int => $idMap[$id] ?? $id, $prerequisiteIds);
                Lesson::whereKey($copiedLessonId)->first()?->prerequisites()->sync($targets);
            }

            return $copy->load('lessons.media');
        });
    }
}
