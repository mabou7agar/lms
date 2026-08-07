<?php

namespace App\Domains\Authoring\Actions\Section;

use App\Domains\Authoring\Models\Section;
use App\Domains\Catalog\Models\Course;
use App\Platform\Shared\Actions\BaseAction;
use Illuminate\Support\Facades\DB;

class ReorderSectionsAction extends BaseAction
{
    /**
     * Section ordering is course-scoped. The course is intentionally NOT an optimistic-lock node
     * (C3 versions only sections + lessons), so this reorder stays server-authoritative: the parent
     * course row is locked for the write window (mirroring DuplicateSectionAction) to serialize
     * concurrent reorders of the same course, guaranteeing a deterministic final ordering.
     *
     * @param  array<int, string>  $orderedPublicIds
     */
    public function execute(Course $course, array $orderedPublicIds): void
    {
        $this->transaction(function () use ($course, $orderedPublicIds): void {
            // Serialize concurrent section reorders for THIS course. Locked via the query builder so
            // this Authoring action keeps no cross-domain dependency on the Catalog Course model.
            DB::table('courses')->where('id', $course->id)->lockForUpdate()->first();

            foreach ($orderedPublicIds as $position => $publicId) {
                Section::where('course_id', $course->id)
                    ->where('public_id', $publicId)
                    ->update(['position' => $position]);
            }
        });
    }
}
