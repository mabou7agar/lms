<?php

namespace App\Contexts\Learning\Http\Controllers\Api\V1;

use App\Contexts\Learning\Exceptions\EnrollmentExpiredException;
use App\Contexts\Learning\Exceptions\NotEnrolledException;
use App\Contexts\Learning\Http\Resources\LearnCourseResource;
use App\Contexts\Learning\Models\Enrollment;
use App\Contexts\Learning\Models\LessonProgress;
use App\Contexts\Learning\Services\LessonAccessService;
use App\Platform\Shared\Curriculum\Contracts\CurriculumReadPort;
use App\Platform\Shared\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class LearnController extends Controller
{
    public function show(Request $request, string $course, CurriculumReadPort $curriculum, LessonAccessService $access): JsonResponse
    {
        $courseRef = $curriculum->findCourseByPublicId($course);
        if ($courseRef === null) {
            throw new NotFoundHttpException('Course not found.');
        }

        $enrollment = Enrollment::where('user_id', $request->user()->id)
            ->where('course_id', $courseRef->id)
            ->active()
            ->first();

        if ($enrollment === null) {
            throw new NotEnrolledException;
        }

        // A company seat outlives neither the purchase that paid for it nor a manager revoking it.
        // Individual enrollments carry no expiry at all, so this can never close the door on someone
        // who bought the course themselves.
        if ($enrollment->hasExpired()) {
            throw new EnrollmentExpiredException;
        }

        $tree = $curriculum->curriculumTree($courseRef->id, publishedOnly: true);

        $completedIds = LessonProgress::where('enrollment_id', $enrollment->id)
            ->where('status', 'completed')->pluck('lesson_id')
            ->map(static fn ($id): int => (int) $id)->all();

        // Accessible = preview OR all prerequisites completed. Resolved for the whole curriculum in
        // ONE prerequisite query using the enrollment and completed ids already in hand, instead of
        // 2–3 queries per lesson via canAccessByUserId(). The access rule is unchanged.
        $lessonRefs = [];
        foreach ($tree['sections'] as $node) {
            foreach ($node['lessons'] as $lessonRef) {
                $lessonRefs[] = $lessonRef;
            }
        }
        $accessibleIds = $access->accessibleLessonIds($lessonRefs, $completedIds);

        return ApiResponse::success(new LearnCourseResource([
            'course' => $courseRef,
            'enrollment' => $enrollment,
            'sections' => $tree['sections'],
            'completed_ids' => $completedIds,
            'accessible_ids' => $accessibleIds,
        ]));
    }
}
