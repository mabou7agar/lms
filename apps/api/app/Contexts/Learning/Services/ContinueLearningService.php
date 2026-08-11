<?php

namespace App\Contexts\Learning\Services;

use App\Contexts\Learning\Enums\LessonProgressStatus;
use App\Contexts\Learning\Models\Enrollment;
use App\Contexts\Learning\Models\LessonProgress;
use App\Platform\Shared\Curriculum\Contracts\CurriculumReadPort;
use App\Platform\Shared\Curriculum\Data\LessonRef;
use App\Platform\Shared\Services\BaseService;
use Illuminate\Support\Collection;

/**
 * Computes the "next lesson to resume" for a learner's active enrollments, ordered by recent
 * activity. Read-only. Curriculum order comes from CurriculumReadPort; the next lesson is returned
 * as a LessonRef (no Authoring model dependency).
 */
class ContinueLearningService extends BaseService
{
    public function __construct(private readonly CurriculumReadPort $curriculum) {}

    /** @return Collection<int, array{enrollment: Enrollment, next_lesson: ?LessonRef}> */
    public function forUserId(int $userId): Collection
    {
        return Enrollment::query()
            ->where('user_id', $userId)
            ->active()
            ->with('course')
            // Bound the hottest learner endpoint: only the most-recently-active enrollments feed the
            // "continue learning" rail, so the per-enrollment next-lesson lookup can't grow unbounded.
            ->orderByDesc('updated_at')
            ->limit(24)
            ->get()
            ->map(fn (Enrollment $enrollment) => [
                'enrollment' => $enrollment,
                'next_lesson' => $this->nextLessonRef($enrollment),
            ])
            ->sortByDesc(fn ($row) => optional($row['enrollment']->updated_at)->getTimestamp())
            ->values();
    }

    public function nextLessonRef(Enrollment $enrollment): ?LessonRef
    {
        $completedIds = LessonProgress::query()
            ->where('enrollment_id', $enrollment->id)
            ->where('status', LessonProgressStatus::Completed->value)
            ->pluck('lesson_id')
            ->flip();

        foreach ($this->curriculum->orderedPublishedLessonRefs($enrollment->course_id) as $ref) {
            if (! $completedIds->has($ref->id)) {
                return $ref;
            }
        }

        return null;
    }
}
