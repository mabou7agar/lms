<?php

namespace App\Contexts\Learning\Http\Controllers\Api\V1;

use App\Contexts\Learning\Http\Resources\CourseLaunchResource;
use App\Contexts\Learning\Http\Resources\ProgressSummaryResource;
use App\Contexts\Learning\Http\Resources\RuntimeCurriculumResource;
use App\Contexts\Learning\Models\Enrollment;
use App\Contexts\Learning\Services\ContinueLearningService;
use App\Contexts\Learning\Services\CourseLaunchService;
use App\Contexts\Learning\Services\CurriculumRuntimeService;
use App\Contexts\Learning\Services\LessonAccessService;
use App\Contexts\Learning\Services\ProgressSummaryService;
use App\Platform\Shared\Curriculum\Contracts\CurriculumReadPort;
use App\Platform\Shared\Curriculum\Data\CourseRef;
use App\Platform\Shared\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Runtime course endpoints: launch shell, runtime curriculum, per-course resume, progress summary.
 * Every action independently authorizes the learner; an unauthorized course is 404 (its existence
 * never leaks) and a non-enrolled learner is 403.
 */
class CourseLaunchController extends Controller
{
    public function launch(Request $request, string $course, CourseLaunchService $service): JsonResponse
    {
        $data = $service->launch($request->user()->id, $course);

        return ApiResponse::success(new CourseLaunchResource($data));
    }

    public function curriculum(Request $request, string $course, CurriculumReadPort $curriculum, LessonAccessService $access, CurriculumRuntimeService $runtime): JsonResponse
    {
        [$courseRef, $enrollment] = $this->resolveEnrolled($request, $course, $curriculum, $access);

        return ApiResponse::success(new RuntimeCurriculumResource([
            'course' => $courseRef,
            'enrollment' => $enrollment,
            'sections' => $runtime->build($enrollment),
        ]));
    }

    public function summary(Request $request, string $course, CurriculumReadPort $curriculum, LessonAccessService $access, ProgressSummaryService $summary): JsonResponse
    {
        [, $enrollment] = $this->resolveEnrolled($request, $course, $curriculum, $access);

        return ApiResponse::success(new ProgressSummaryResource($summary->forCourse($enrollment)));
    }

    public function resume(Request $request, string $course, CurriculumReadPort $curriculum, LessonAccessService $access, ContinueLearningService $continue): JsonResponse
    {
        [, $enrollment] = $this->resolveEnrolled($request, $course, $curriculum, $access);

        $next = $continue->nextLessonRef($enrollment);

        return ApiResponse::success([
            'resume_lesson_id' => $next?->publicId,
            'title' => $next?->title,
        ]);
    }

    /**
     * @return array{0: CourseRef, 1: Enrollment}
     */
    private function resolveEnrolled(Request $request, string $course, CurriculumReadPort $curriculum, LessonAccessService $access): array
    {
        $courseRef = $curriculum->findCourseByPublicId($course);
        if ($courseRef === null || ! $curriculum->isCourseEnrollable($courseRef->id)) {
            throw new NotFoundHttpException('Course not found.');
        }

        $enrollment = $access->activeEnrollmentByUserId($request->user()->id, $courseRef->id);
        if ($enrollment === null) {
            // "Not enrolled" and "your access ran out" are different problems with different
            // remedies, and the player can only say the right thing if the API distinguishes them.
            $access->denyAccess($request->user()->id, $courseRef->id);
        }

        return [$courseRef, $enrollment];
    }
}
