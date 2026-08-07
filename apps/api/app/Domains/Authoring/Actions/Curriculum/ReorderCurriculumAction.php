<?php

namespace App\Domains\Authoring\Actions\Curriculum;

use App\Domains\Authoring\Events\CurriculumReordered;
use App\Domains\Authoring\Models\Lesson;
use App\Domains\Authoring\Models\Section;
use App\Domains\Catalog\Models\Course;
use App\Platform\Shared\Actions\BaseAction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Applies a full drag-and-drop reorder of a course's curriculum in one transaction:
 * section order + per-section lesson order (and lesson re-parenting across sections).
 */
class ReorderCurriculumAction extends BaseAction
{
    /**
     * @param  array<int, array{id: string, lessons?: array<int, string>}>  $tree
     */
    public function execute(Course $course, array $tree): void
    {
        $this->transaction(function () use ($course, $tree): void {
            // Server-authoritative ordering: lock the parent course row for the write window so
            // concurrent whole-tree reorders serialize into a single deterministic final ordering
            // (mirrors DuplicateSectionAction / ReorderSectionsAction). The course is course-scoped
            // and not an optimistic-lock node, so no per-node version token is compared here.
            DB::table('courses')->where('id', $course->id)->lockForUpdate()->first();

            foreach ($tree as $sectionPosition => $node) {
                $section = Section::where('course_id', $course->id)
                    ->where('public_id', $node['id'])
                    ->first();

                if ($section === null) {
                    continue;
                }

                $section->update(['position' => $sectionPosition]);

                foreach ($node['lessons'] ?? [] as $lessonPosition => $lessonPublicId) {
                    // Anti-tampering: only re-parent a lesson that already belongs to a section
                    // within THIS course. A foreign lesson id (from another course) matches nothing
                    // and is silently ignored — it can never be moved into this course.
                    Lesson::where('public_id', $lessonPublicId)
                        ->whereHas('section', fn (Builder $q) => $q->where('course_id', $course->id))
                        ->update([
                            'section_id' => $section->id,
                            'position' => $lessonPosition,
                        ]);
                }
            }
        });

        CurriculumReordered::dispatch($course);
    }
}
