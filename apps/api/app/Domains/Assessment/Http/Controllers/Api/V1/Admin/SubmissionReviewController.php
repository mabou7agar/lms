<?php

namespace App\Domains\Assessment\Http\Controllers\Api\V1\Admin;

use App\Domains\Assessment\Http\Requests\GradeSubmissionRequest;
use App\Domains\Assessment\Http\Requests\RequestChangesRequest;
use App\Domains\Assessment\Http\Resources\SubmissionListResource;
use App\Domains\Assessment\Http\Resources\SubmissionResource;
use App\Domains\Assessment\Models\Assignment;
use App\Domains\Assessment\Models\AssignmentSubmission;
use App\Domains\Assessment\Services\GradingService;
use App\Platform\Identity\Contracts\Actor;
use App\Platform\Shared\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Instructor grading surface: the submission queue and the grade/request-changes/release actions.
 * Every route funnels through a 404-on-denial authorization so a grader cannot see or act on work
 * outside their courses. Responses use the INSTRUCTOR resource (private notes included) — never a
 * learner resource.
 */
class SubmissionReviewController
{
    public function __construct(private readonly GradingService $grading) {}

    /** Paginated grading queue for one assignment, newest submissions first, optional status filter. */
    public function index(Request $request, Assignment $assignment): JsonResponse
    {
        $this->authorizeManage($request, $assignment);

        $query = AssignmentSubmission::query()
            ->where('assignment_id', $assignment->id)
            ->whereNot('status', 'draft')
            ->with(['grade', 'assignment'])
            ->orderByDesc('submitted_at')
            ->orderByDesc('id');

        if (is_string($status = $request->query('status'))) {
            $query->where('status', $status);
        }

        return ApiResponse::paginated(
            $query->paginate((int) $request->integer('per_page', 25)),
            SubmissionListResource::class,
        );
    }

    /** Open one submission for review — full body, files (secure media ids) and current grade. */
    public function show(Request $request, AssignmentSubmission $submission): JsonResponse
    {
        $this->authorizeGrade($request, $submission);

        return ApiResponse::success(new SubmissionResource($submission->load(['files', 'grade', 'assignment'])));
    }

    public function grade(GradeSubmissionRequest $request, AssignmentSubmission $submission): JsonResponse
    {
        $graderId = $this->authorizeGrade($request, $submission);

        $this->grading->grade($submission->load('assignment'), $graderId, $request->validated());

        return ApiResponse::updated(new SubmissionResource($submission->fresh(['files', 'grade', 'assignment'])));
    }

    public function requestChanges(RequestChangesRequest $request, AssignmentSubmission $submission): JsonResponse
    {
        $graderId = $this->authorizeGrade($request, $submission);

        $this->grading->requestChanges($submission, $graderId, $request->validated()['note'] ?? null);

        return ApiResponse::updated(new SubmissionResource($submission->fresh(['files', 'grade', 'assignment'])));
    }

    public function release(Request $request, AssignmentSubmission $submission): JsonResponse
    {
        $graderId = $this->authorizeGrade($request, $submission);

        $this->grading->release($submission->load('assignment'), $graderId);

        return ApiResponse::updated(new SubmissionResource($submission->fresh(['files', 'grade', 'assignment'])));
    }

    public function unrelease(Request $request, AssignmentSubmission $submission): JsonResponse
    {
        $graderId = $this->authorizeGrade($request, $submission);

        $this->grading->unrelease($submission, $graderId);

        return ApiResponse::updated(new SubmissionResource($submission->fresh(['files', 'grade', 'assignment'])));
    }

    private function authorizeManage(Request $request, Assignment $assignment): void
    {
        if (! Gate::forUser($this->actor($request))->allows('grade', $assignment)) {
            throw new NotFoundHttpException('Assignment not found.');
        }
    }

    private function authorizeGrade(Request $request, AssignmentSubmission $submission): int
    {
        $actor = $this->actor($request);

        if (! Gate::forUser($actor)->allows('grade', $submission)) {
            throw new NotFoundHttpException('Submission not found.');
        }

        return $actor->actorId();
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
