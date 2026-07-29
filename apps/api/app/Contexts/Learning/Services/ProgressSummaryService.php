<?php

namespace App\Contexts\Learning\Services;

use App\Contexts\Learning\Enums\EnrollmentStatus;
use App\Contexts\Learning\Enums\LessonProgressStatus;
use App\Contexts\Learning\Models\Enrollment;
use App\Contexts\Learning\Models\LessonProgress;
use App\Contexts\Learning\Runtime\Data\ProgressSummaryData;
use App\Platform\Shared\Curriculum\Contracts\CurriculumReadPort;
use App\Platform\Shared\Services\BaseService;

/**
 * Read-only learner progress summary for a single course: counts + the resume pointer. Reuses the
 * enrollment's stored percentage (authored by ProgressService) rather than recomputing it, so the
 * summary can never drift from what completion recalculation produced.
 */
class ProgressSummaryService extends BaseService
{
    public function __construct(
        private readonly CurriculumReadPort $curriculum,
        private readonly ContinueLearningService $continue,
    ) {}

    public function forCourse(Enrollment $enrollment): ProgressSummaryData
    {
        $courseRef = $this->curriculum->courseRefById($enrollment->courseId());
        $publishedIds = $this->curriculum->publishedLessonIdsForCourse($enrollment->courseId());

        $completed = $publishedIds === [] ? 0 : LessonProgress::query()
            ->where('enrollment_id', $enrollment->id)
            ->whereIn('lesson_id', $publishedIds)
            ->where('status', LessonProgressStatus::Completed->value)
            ->count();

        $resume = $this->continue->nextLessonRef($enrollment);

        return new ProgressSummaryData(
            coursePublicId: $courseRef->publicId ?? '',
            enrollmentStatus: $enrollment->statusEnum()->value,
            progressPercentage: $enrollment->progressPercentage(),
            totalLessons: count($publishedIds),
            completedLessons: $completed,
            courseCompleted: $enrollment->statusEnum() === EnrollmentStatus::Completed,
            resumeLessonPublicId: $resume?->publicId,
        );
    }
}
