<?php

namespace App\Domains\Assessment\Http\Controllers\Api\V1\Learner;

use App\Domains\Assessment\Http\Requests\AttachFileRequest;
use App\Domains\Assessment\Http\Requests\SaveDraftRequest;
use App\Domains\Assessment\Http\Resources\LearnerSubmissionResource;
use App\Domains\Assessment\Models\Assignment;
use App\Domains\Assessment\Models\AssignmentSubmission;
use App\Domains\Assessment\Models\SubmissionFile;
use App\Domains\Assessment\Services\SubmissionService;
use App\Platform\Identity\Contracts\Actor;
use App\Platform\Shared\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Learner write surface: draft content, file attach/detach, submit and resubmit. Every response is
 * the LEARNER resource, so a private note or an unreleased score can never leak here. Ownership is
 * asserted directly (a submission belongs to exactly one learner).
 */
class SubmissionController
{
    public function __construct(private readonly SubmissionService $submissions) {}

    public function saveDraft(SaveDraftRequest $request, Assignment $assignment): JsonResponse
    {
        $this->assertVisible($assignment);
        $draft = $this->submissions->saveDraft($assignment, $this->actorId($request), $request->validated());

        return ApiResponse::updated(new LearnerSubmissionResource($draft->load(['files', 'grade'])), 'Draft saved.');
    }

    public function attachFile(AttachFileRequest $request, Assignment $assignment): JsonResponse
    {
        $this->assertVisible($assignment);
        $userId = $this->actorId($request);

        // Ensure a draft exists (resume or create), then attach to it.
        $draft = $this->submissions->saveDraft($assignment, $userId, []);
        $this->submissions->attachFile($draft, (string) $request->validated()['media_id'], $userId);

        return ApiResponse::updated(new LearnerSubmissionResource($draft->fresh(['files', 'grade'])), 'File attached.');
    }

    public function detachFile(Request $request, AssignmentSubmission $submission, SubmissionFile $file): JsonResponse
    {
        $this->assertOwned($request, $submission);
        $this->submissions->detachFile($submission, $file);

        return ApiResponse::deleted('File removed.');
    }

    public function submit(Request $request, Assignment $assignment): JsonResponse
    {
        $this->assertVisible($assignment);
        $submission = $this->submissions->submit($assignment, $this->actorId($request));

        return ApiResponse::updated(new LearnerSubmissionResource($submission->load(['files', 'grade'])), 'Submitted.');
    }

    public function resubmit(Request $request, Assignment $assignment): JsonResponse
    {
        $this->assertVisible($assignment);
        $draft = $this->submissions->resubmitDraft($assignment, $this->actorId($request));

        return ApiResponse::created(new LearnerSubmissionResource($draft->load(['files', 'grade'])), 'New attempt opened.');
    }

    public function show(Request $request, AssignmentSubmission $submission): JsonResponse
    {
        $this->assertOwned($request, $submission);

        return ApiResponse::success(new LearnerSubmissionResource($submission->load(['files', 'grade'])));
    }

    private function assertVisible(Assignment $assignment): void
    {
        if (! $assignment->isPublished()) {
            throw new NotFoundHttpException('Assignment not found.');
        }
    }

    private function assertOwned(Request $request, AssignmentSubmission $submission): void
    {
        if ((int) $submission->user_id !== $this->actorId($request)) {
            throw new NotFoundHttpException('Submission not found.');
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
