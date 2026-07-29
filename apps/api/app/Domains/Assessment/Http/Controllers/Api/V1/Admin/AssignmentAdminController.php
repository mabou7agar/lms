<?php

namespace App\Domains\Assessment\Http\Controllers\Api\V1\Admin;

use App\Domains\Assessment\Http\Requests\BuildRubricRequest;
use App\Domains\Assessment\Http\Requests\SaveAssignmentRequest;
use App\Domains\Assessment\Http\Resources\AssignmentResource;
use App\Domains\Assessment\Http\Resources\RubricResource;
use App\Domains\Assessment\Models\Assignment;
use App\Domains\Assessment\Services\AssignmentService;
use App\Platform\Identity\Contracts\Actor;
use App\Platform\Identity\Contracts\CourseAccessPort;
use App\Platform\Shared\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Instructor authoring surface for assignments and rubrics. Course-scoped routes resolve the course
 * PUBLIC id through CourseAccessPort (this context never imports the Course model). Bound-assignment
 * routes convert a policy denial into a 404 so an instructor cannot probe for assignments outside
 * the courses they train.
 */
class AssignmentAdminController
{
    public function __construct(
        private readonly CourseAccessPort $courses,
        private readonly AssignmentService $service,
    ) {}

    public function index(Request $request, string $course): JsonResponse
    {
        $courseId = $this->manageableCourse($request, $course);

        $assignments = Assignment::query()
            ->where('course_id', $courseId)
            ->orderByDesc('id')
            ->paginate((int) $request->integer('per_page', 25));

        return ApiResponse::paginated($assignments, AssignmentResource::class);
    }

    public function store(SaveAssignmentRequest $request, string $course): JsonResponse
    {
        $courseId = $this->manageableCourse($request, $course);
        $actor = $this->actor($request);

        $assignment = $this->service->createAssignment($courseId, $actor->actorId(), $request->validated());

        return ApiResponse::created(new AssignmentResource($assignment));
    }

    public function show(Request $request, Assignment $assignment): JsonResponse
    {
        $this->authorizeManage($request, $assignment);

        return ApiResponse::success(new AssignmentResource($assignment));
    }

    public function update(SaveAssignmentRequest $request, Assignment $assignment): JsonResponse
    {
        $this->authorizeManage($request, $assignment);

        return ApiResponse::updated(new AssignmentResource($this->service->updateAssignment($assignment, $request->validated())));
    }

    public function destroy(Request $request, Assignment $assignment): JsonResponse
    {
        $this->authorizeManage($request, $assignment);

        $this->service->deleteAssignment($assignment);

        return ApiResponse::deleted('Assignment deleted.');
    }

    public function publish(Request $request, Assignment $assignment): JsonResponse
    {
        $this->authorizeManage($request, $assignment);

        return ApiResponse::updated(new AssignmentResource($this->service->publish($assignment)));
    }

    public function unpublish(Request $request, Assignment $assignment): JsonResponse
    {
        $this->authorizeManage($request, $assignment);

        return ApiResponse::updated(new AssignmentResource($this->service->unpublish($assignment)));
    }

    public function rubric(BuildRubricRequest $request, Assignment $assignment): JsonResponse
    {
        $this->authorizeManage($request, $assignment);

        $rubric = $this->service->buildRubric($assignment, $request->validated());

        return ApiResponse::success(
            new RubricResource($rubric),
            'Updated.',
            200,
            [],
            JSON_PRESERVE_ZERO_FRACTION,
        );
    }

    /** Policy denial becomes a 404 (no existence leak) rather than a 403. */
    private function authorizeManage(Request $request, Assignment $assignment): void
    {
        if (! Gate::forUser($this->actor($request))->allows('update', $assignment)) {
            throw new NotFoundHttpException('Assignment not found.');
        }
    }

    private function manageableCourse(Request $request, string $coursePublicId): int
    {
        $courseId = $this->courses->manageableCourseId($this->actor($request), $coursePublicId);

        if ($courseId === null) {
            throw new NotFoundHttpException('Course not found.');
        }

        return $courseId;
    }

    private function actor(Request $request): Actor
    {
        $actor = $request->user();

        if (! $actor instanceof Actor) {
            throw new NotFoundHttpException('Not found.');
        }

        return $actor;
    }
}
