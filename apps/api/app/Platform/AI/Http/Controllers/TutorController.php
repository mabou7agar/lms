<?php

declare(strict_types=1);

namespace App\Platform\AI\Http\Controllers;

use App\Platform\AI\Tutor\TutorService;
use App\Platform\Shared\Curriculum\Contracts\CurriculumReadPort;
use App\Platform\Shared\Learning\Contracts\CourseEnrollmentPort;
use App\Platform\Shared\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/v1/ai/tutor — the STUDENT AI TUTOR.
 *
 * A learner asks a question about ONE course. Access is scoped in two independent layers:
 *   1. the course is resolved by public id (404 for an unknown course), and
 *   2. the learner MUST be actively enrolled in it (403 otherwise) — checked via the Shared
 *      CourseEnrollmentPort, the single definition of "is this learner enrolled".
 * Only then does {@see TutorService} run, grounding the answer strictly in that course's indexed
 * content and returning the retrieved chunks as citations. A non-enrolled learner can never reach the
 * model, and the course-scoped retrieval means the tutor can never surface another course's, another
 * tenant's, or unpublished content.
 */
final class TutorController extends AbstractAiController
{
    public function ask(
        Request $request,
        TutorService $tutor,
        CurriculumReadPort $curriculum,
        CourseEnrollmentPort $enrollment,
    ): JsonResponse {
        $data = $request->validate([
            'course_id' => ['required', 'string'],
            'question' => ['required', 'string', 'min:3', 'max:2000'],
        ]);

        $actor = $this->actor($request);

        // Resolve the course by its public id; indistinguishable 404 for unknown ids.
        $course = $curriculum->findCourseByPublicId((string) $data['course_id']);
        if ($course === null) {
            return ApiResponse::error('COURSE_NOT_FOUND', 'Course not found.', [], 404);
        }

        // Enrollment gate: a learner who is not enrolled in this course may not use its tutor.
        if (! $enrollment->isEnrolled($course->id, $actor->actorId())) {
            return ApiResponse::error(
                'NOT_ENROLLED',
                'You must be enrolled in this course to use its AI tutor.',
                [],
                403,
            );
        }

        return $this->runGuarded(fn (): JsonResponse => ApiResponse::success(
            $tutor->answer(
                question: (string) $data['question'],
                courseId: $course->id,
                userId: $actor->actorId(),
            )->toArray(),
        ));
    }
}
