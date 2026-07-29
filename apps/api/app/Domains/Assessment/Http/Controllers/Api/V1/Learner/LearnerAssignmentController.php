<?php

namespace App\Domains\Assessment\Http\Controllers\Api\V1\Learner;

use App\Domains\Assessment\Http\Resources\LearnerAssignmentResource;
use App\Domains\Assessment\Http\Resources\LearnerSubmissionResource;
use App\Domains\Assessment\Models\Assignment;
use App\Domains\Assessment\Models\AssignmentSubmission;
use App\Platform\Identity\Contracts\Actor;
use App\Platform\Shared\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Learner read surface. An unpublished assignment 404s (it does not exist as far as a learner is
 * concerned). History returns only the caller's OWN submissions, through the learner resource that
 * strips private notes and unreleased grades.
 */
class LearnerAssignmentController
{
    public function show(Request $request, Assignment $assignment): JsonResponse
    {
        $this->assertVisible($request, $assignment);

        return ApiResponse::success(new LearnerAssignmentResource($assignment));
    }

    public function history(Request $request, Assignment $assignment): JsonResponse
    {
        $userId = $this->actorId($request);
        $this->assertVisible($request, $assignment);

        $submissions = AssignmentSubmission::query()
            ->where('assignment_id', $assignment->id)
            ->where('user_id', $userId)
            ->with(['files', 'grade'])
            ->orderByDesc('attempt_no')
            ->paginate((int) $request->integer('per_page', 25));

        return ApiResponse::paginated($submissions, LearnerSubmissionResource::class);
    }

    private function assertVisible(Request $request, Assignment $assignment): void
    {
        // Ensure the caller is authenticated; a published assignment is visible to learners.
        $this->actorId($request);

        if (! $assignment->isPublished()) {
            throw new NotFoundHttpException('Assignment not found.');
        }
    }

    private function actorId(Request $request): int
    {
        $actor = $request->user();

        if (! $actor instanceof Actor) {
            throw new NotFoundHttpException('Not found.');
        }

        return $actor->actorId();
    }
}
