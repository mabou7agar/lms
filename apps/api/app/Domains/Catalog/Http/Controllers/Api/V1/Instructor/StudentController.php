<?php

namespace App\Domains\Catalog\Http\Controllers\Api\V1\Instructor;

use App\Contexts\Learning\Models\Enrollment;
use App\Domains\Catalog\Analytics\LearnerDrillDownService;
use App\Domains\Catalog\Http\Resources\Instructor\InstructorLearnerProgressResource;
use App\Domains\Catalog\Http\Resources\Instructor\InstructorStudentResource;
use App\Domains\Catalog\Models\Course;
use App\Platform\Identity\Contracts\UserLookupPort;
use App\Platform\Shared\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class StudentController extends InstructorController
{
    /** GET /teach/courses/{course}/students — paginated roster with progress (404 if not mine). */
    public function index(Request $request, Course $course, UserLookupPort $users): JsonResponse
    {
        $course = $this->ownedCourse($request, $course);

        $perPage = (int) config('catalog.pagination.per_page', 15);
        $paginator = Enrollment::query()
            ->where('course_id', $course->id)
            ->latest('enrolled_at')
            ->paginate($perPage);

        $ids = $paginator->getCollection()->pluck('user_id')->map(static fn ($v): int => (int) $v)->all();
        $refs = $users->refsByIds($ids);

        $paginator->getCollection()->transform(function (Enrollment $enrollment) use ($refs): Enrollment {
            $ref = $refs[(int) $enrollment->user_id] ?? null;
            $enrollment->setAttribute('student_name', $ref?->name);
            $enrollment->setAttribute('student_public_id', $ref?->publicId);

            return $enrollment;
        });

        return ApiResponse::paginated($paginator, InstructorStudentResource::class);
    }

    /**
     * GET /teach/courses/{course}/students/{student} — one learner's progress drill-down.
     *
     * Same ownership guard as the roster: `ownedCourse` 404s a course the caller does not train, so a
     * non-owned course is indistinguishable from a missing one. `{student}` is a PUBLIC id resolved
     * through the Identity port (never a route-bound Identity model), and a learner who is not
     * enrolled 404s with the same status as an unknown one — the endpoint is not an enrolment oracle.
     * All progress / assessment / certificate data is read across the boundary through Shared ports.
     */
    public function show(
        Request $request,
        Course $course,
        string $student,
        UserLookupPort $users,
        LearnerDrillDownService $drill,
    ): JsonResponse {
        $course = $this->ownedCourse($request, $course);

        $ref = $users->refByPublicId($student);

        if ($ref === null) {
            throw new NotFoundHttpException('Student not found.');
        }

        $report = $drill->forLearner((int) $course->id, $ref);

        if ($report === null) {
            throw new NotFoundHttpException('Student not found.');
        }

        return ApiResponse::success(new InstructorLearnerProgressResource($report));
    }
}
