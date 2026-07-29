<?php

namespace App\Contexts\Learning\Services;

use App\Contexts\Learning\Enums\LessonLockReason;
use App\Contexts\Learning\Exceptions\LessonLockedException;
use App\Contexts\Learning\Exceptions\NotEnrolledException;
use App\Contexts\Learning\Models\Enrollment;
use App\Platform\Shared\Curriculum\Contracts\CurriculumReadPort;
use App\Platform\Shared\Learning\Contracts\CourseNavigationPort;
use App\Platform\Shared\Learning\Contracts\LessonAvailabilityPort;
use App\Platform\Shared\Services\BaseService;

/**
 * The runtime authorization rule for launching / progressing a lesson. Composes three gates on top
 * of enrollment, WITHOUT rewriting the existing LessonAccessService (which the legacy endpoints and
 * their tests still use unchanged):
 *
 *   1. Enrollment      — an active enrollment in the lesson's course (always required).
 *   2. Sequencing      — prerequisite completion, UNLESS the course allows free navigation.
 *   3. Drip scheduling — the lesson's server-side release instant must not be in the future.
 *
 * Preview lessons bypass sequencing and drip (they are marketing surface), but still need an
 * enrollment context so progress has somewhere to live — identical to LessonAccessService.
 *
 * Every launch is authorized here INDEPENDENTLY; a prior curriculum load grants nothing.
 */
class RuntimeAccessService extends BaseService
{
    public function __construct(
        private readonly LessonAccessService $access,
        private readonly CurriculumReadPort $curriculum,
        private readonly CourseNavigationPort $navigation,
        private readonly LessonAvailabilityPort $availability,
    ) {}

    public function canLaunch(int $userId, int $lessonId): bool
    {
        try {
            $this->assertLaunchable($userId, $lessonId);

            return true;
        } catch (NotEnrolledException|LessonLockedException) {
            return false;
        }
    }

    /** @throws NotEnrolledException|LessonLockedException */
    public function assertLaunchable(int $userId, int $lessonId): Enrollment
    {
        $ref = $this->curriculum->lessonRefById($lessonId);
        $courseId = $ref->courseId ?? 0;

        // Sequencing gate. Free navigation relaxes prerequisites to an enrollment-only check;
        // otherwise defer to the existing prerequisite rule (which also asserts enrollment).
        if ($ref !== null && ! $ref->isPreview && $this->navigation->allowsFreeNavigation($courseId)) {
            $enrollment = $this->access->activeEnrollmentByUserId($userId, $courseId);
            if ($enrollment === null) {
                throw new NotEnrolledException;
            }
        } else {
            $enrollment = $this->access->assertAccessByUserId($userId, $lessonId);
        }

        // Drip gate. Preview lessons are never drip-locked.
        if ($ref !== null && ! $ref->isPreview) {
            $releaseAt = $this->availability->releaseAtForLessons(
                [$lessonId],
                $enrollment->enrolled_at ?? $enrollment->created_at ?? now(),
            )[$lessonId] ?? null;

            if ($releaseAt !== null && $releaseAt->isFuture()) {
                throw new LessonLockedException(
                    'This lesson is not yet released.',
                    ['reason' => LessonLockReason::Drip->value, 'available_at' => $releaseAt->toIso8601String()],
                );
            }
        }

        return $enrollment;
    }
}
