<?php

namespace App\Contexts\Learning\Services;

use App\Contexts\Learning\Enums\LessonProgressStatus;
use App\Contexts\Learning\Exceptions\NotEnrolledException;
use App\Contexts\Learning\Models\LessonProgress;
use App\Contexts\Learning\Runtime\Data\CourseLaunchData;
use App\Platform\Shared\Curriculum\Contracts\CurriculumReadPort;
use App\Platform\Shared\Services\BaseService;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Produces the authorized learner course shell for a launch. Enforces, in order:
 *   - the course exists AND is published/enrollable (otherwise 404 — a future/unpublished course is
 *     indistinguishable from a missing one, so its existence never leaks);
 *   - the learner has an active enrollment (otherwise NotEnrolled → 403).
 *
 * Only published lessons are counted. The resume pointer reuses ContinueLearningService so "where
 * do I go next" is computed in exactly one place.
 */
class CourseLaunchService extends BaseService
{
    public function __construct(
        private readonly CurriculumReadPort $curriculum,
        private readonly LessonAccessService $access,
        private readonly ContinueLearningService $continue,
    ) {}

    public function launch(int $userId, string $coursePublicId): CourseLaunchData
    {
        $courseRef = $this->curriculum->findCourseByPublicId($coursePublicId);

        if ($courseRef === null || ! $this->curriculum->isCourseEnrollable($courseRef->id)) {
            throw new NotFoundHttpException('Course not found.');
        }

        $enrollment = $this->access->activeEnrollmentByUserId($userId, $courseRef->id);
        if ($enrollment === null) {
            throw new NotEnrolledException;
        }

        $publishedIds = $this->curriculum->publishedLessonIdsForCourse($courseRef->id);
        $total = count($publishedIds);

        $completed = $publishedIds === [] ? 0 : LessonProgress::query()
            ->where('enrollment_id', $enrollment->id)
            ->whereIn('lesson_id', $publishedIds)
            ->where('status', LessonProgressStatus::Completed->value)
            ->count();

        $resume = $this->continue->nextLessonRef($enrollment);

        return new CourseLaunchData(
            coursePublicId: $courseRef->publicId,
            title: $courseRef->title,
            slug: $courseRef->slug,
            enrollmentPublicId: $enrollment->publicId(),
            enrollmentStatus: $enrollment->statusEnum()->value,
            progressPercentage: $enrollment->progressPercentage(),
            totalLessons: $total,
            completedLessons: $completed,
            resumeLessonPublicId: $resume?->publicId,
            resumeLessonTitle: $resume?->title,
        );
    }
}
