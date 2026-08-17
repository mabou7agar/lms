<?php

namespace App\Contexts\Learning\Services;

use App\Contexts\Learning\Exceptions\EnrollmentExpiredException;
use App\Contexts\Learning\Exceptions\LessonLockedException;
use App\Contexts\Learning\Exceptions\NotEnrolledException;
use App\Contexts\Learning\Models\Enrollment;
use App\Contexts\Learning\Models\LessonProgress;
use App\Platform\Shared\Curriculum\Contracts\CurriculumReadPort;
use App\Platform\Shared\Curriculum\Data\LessonRef;
use App\Platform\Shared\Services\BaseService;

/**
 * Central access rule for lessons: preview lessons are open (but still need an enrollment context);
 * otherwise the user must have an active enrollment in the lesson's course AND have completed the
 * lesson's prerequisites. All curriculum reads (course-of-lesson, isPreview, prerequisites) go
 * through CurriculumReadPort — no Authoring/Catalog model dependency.
 */
class LessonAccessService extends BaseService
{
    public function __construct(private readonly CurriculumReadPort $curriculum) {}

    public function courseIdForLessonId(int $lessonId): int
    {
        return $this->curriculum->courseIdForLesson($lessonId) ?? 0;
    }

    /**
     * The enrollment that grants this learner access to the course, or null. Access survives course
     * completion (status 'completed'), so finishing the last lesson never locks the learner out of
     * /viewed, /curriculum, resume or launch; a cancelled/soft-deleted enrollment denies it, and so
     * does an elapsed access window.
     *
     * notExpired() belongs here and not only on CourseEnrollmentPort: this is the query the whole
     * player runtime resolves through, so an enrollment whose window has closed must stop resolving
     * at this point or the curriculum, resume, progress and launch endpoints all keep serving it.
     */
    public function activeEnrollmentByUserId(int $userId, int $courseId): ?Enrollment
    {
        return Enrollment::query()
            ->where('user_id', $userId)
            ->where('course_id', $courseId)
            ->grantsAccess()
            ->notExpired()
            ->first();
    }

    /**
     * Did this learner once have access that has since run out? Callers use it to refuse with
     * "your access ended" instead of "you are not enrolled" — a learner who paid and finished their
     * window is not the same person as one who never enrolled, and telling them otherwise sends
     * them to support instead of to renewal.
     */
    public function accessWindowElapsed(int $userId, int $courseId): bool
    {
        return Enrollment::query()
            ->where('user_id', $userId)
            ->where('course_id', $courseId)
            ->grantsAccess()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->exists();
    }

    /** Throws the exception that describes why this learner has no live enrollment. */
    public function denyAccess(int $userId, int $courseId): never
    {
        throw $this->accessWindowElapsed($userId, $courseId)
            ? new EnrollmentExpiredException
            : new NotEnrolledException;
    }

    public function canAccessByUserId(int $userId, int $lessonId): bool
    {
        try {
            $this->assertAccessByUserId($userId, $lessonId);

            return true;
        } catch (EnrollmentExpiredException|NotEnrolledException|LessonLockedException) {
            return false;
        }
    }

    /** Throws EnrollmentExpiredException, NotEnrolledException or LessonLockedException. */
    public function assertAccessByUserId(int $userId, int $lessonId): Enrollment
    {
        $ref = $this->curriculum->lessonRefById($lessonId);
        $courseId = $ref?->courseId ?? 0;
        $enrollment = $this->activeEnrollmentByUserId($userId, $courseId);

        // Preview lessons are viewable, but still need an enrollment context for progress.
        if ($enrollment === null) {
            $this->denyAccess($userId, $courseId);
        }

        if ($ref !== null && ! $ref->isPreview && ! $this->prerequisitesMetByIds($enrollment, $ref->prerequisiteLessonIds)) {
            throw new LessonLockedException;
        }

        return $enrollment;
    }

    /**
     * The subset of the given lessons that an already-enrolled learner can access — the batched
     * equivalent of calling canAccessByUserId() per lesson, in ONE prerequisite query plus PHP.
     *
     * Semantics are identical to assertAccessByUserId(): with an active enrollment (which the caller
     * has verified — every lesson belongs to that enrollment's course), a preview lesson is
     * accessible, and a non-preview lesson is accessible only when every prerequisite has been
     * completed. The `completedLessonIds` are the enrollment's completed lessons (the same set the
     * per-lesson prerequisite COUNT queried), so no progress is re-read. Accessible ids are returned
     * in input order, matching the previous loop's append order exactly.
     *
     * @param  list<LessonRef>  $lessonRefs  lessons of the enrollment's course (isPreview populated)
     * @param  array<int, int>  $completedLessonIds
     * @return list<int>
     */
    public function accessibleLessonIds(array $lessonRefs, array $completedLessonIds): array
    {
        if ($lessonRefs === []) {
            return [];
        }

        $lessonIds = array_map(static fn (LessonRef $ref): int => $ref->id, $lessonRefs);
        $prerequisites = $this->curriculum->prerequisitesForLessonIds($lessonIds);
        $completed = array_flip($completedLessonIds);

        $accessible = [];

        foreach ($lessonRefs as $ref) {
            if ($ref->isPreview || $this->allCompleted($prerequisites[$ref->id] ?? [], $completed)) {
                $accessible[] = $ref->id;
            }
        }

        return $accessible;
    }

    /**
     * @param  list<int>  $prerequisiteIds
     * @param  array<int, int>  $completed  completed lesson id => position (from array_flip)
     */
    private function allCompleted(array $prerequisiteIds, array $completed): bool
    {
        foreach ($prerequisiteIds as $prerequisiteId) {
            if (! isset($completed[$prerequisiteId])) {
                return false;
            }
        }

        return true;
    }

    /** @param list<int> $prerequisiteIds */
    private function prerequisitesMetByIds(Enrollment $enrollment, array $prerequisiteIds): bool
    {
        if ($prerequisiteIds === []) {
            return true;
        }

        $completed = LessonProgress::query()
            ->where('enrollment_id', $enrollment->id)
            ->whereIn('lesson_id', $prerequisiteIds)
            ->where('status', 'completed')
            ->count();

        return $completed === count($prerequisiteIds);
    }
}
