<?php

declare(strict_types=1);

namespace App\Domains\Qna\Http\Controllers\Api\V1;

use App\Domains\Qna\Actions\AcceptAnswerAction;
use App\Domains\Qna\Actions\AnswerQuestionAction;
use App\Domains\Qna\Actions\DeleteAnswerAction;
use App\Domains\Qna\Actions\UpdateAnswerAction;
use App\Domains\Qna\Http\Resources\AnswerResource;
use App\Domains\Qna\Http\Resources\QuestionResource;
use App\Domains\Qna\Models\CourseQuestion;
use App\Domains\Qna\Models\QuestionAnswer;
use App\Platform\Shared\Moderation\Enums\ReportReason;
use App\Platform\Shared\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Answer + acceptance surface. Creating an answer re-checks course access in the action; editing,
 * deleting and reporting funnel through QuestionAnswerPolicy; accepting funnels through
 * CourseQuestionPolicy::accept (author or instructor).
 */
final class AnswerController extends QnaController
{
    /** POST /v1/questions/{question}/answers — post an answer (access enforced in the action). */
    public function store(Request $request, CourseQuestion $question, AnswerQuestionAction $action): JsonResponse
    {
        $actor = $this->actor($request);
        // Participation gate (view) first; the action then re-verifies access and derives is_instructor.
        Gate::forUser($actor)->authorize('view', $question);

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:10000'],
        ]);

        $answer = $action->execute($actor, $question, $validated['body']);

        $authors = $this->authorsFor([$answer->user_id]);

        return ApiResponse::created(new AnswerResource($answer, $authors[(int) $answer->user_id] ?? null));
    }

    /** POST /v1/answers/{answer}/accept — question author or instructor accepts this answer. */
    public function accept(Request $request, QuestionAnswer $answer, AcceptAnswerAction $action): JsonResponse
    {
        $actor = $this->actor($request);
        $question = $this->questionForOr404($answer);

        Gate::forUser($actor)->authorize('accept', $question);

        $question = $action->execute($question, $answer, $actor->actorId());

        $authors = $this->authorsFor([$question->user_id]);

        return ApiResponse::updated(new QuestionResource($question, $authors[(int) $question->user_id] ?? null));
    }

    /** PATCH /v1/answers/{answer} — edit your own answer. */
    public function update(Request $request, QuestionAnswer $answer, UpdateAnswerAction $action): JsonResponse
    {
        $actor = $this->actor($request);
        Gate::forUser($actor)->authorize('update', $answer);

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:10000'],
        ]);

        $answer = $action->execute($answer, $validated['body']);

        $authors = $this->authorsFor([$answer->user_id]);

        return ApiResponse::updated(new AnswerResource($answer, $authors[(int) $answer->user_id] ?? null));
    }

    /** DELETE /v1/answers/{answer} — soft-delete your own answer. */
    public function destroy(Request $request, QuestionAnswer $answer, DeleteAnswerAction $action): JsonResponse
    {
        $actor = $this->actor($request);
        Gate::forUser($actor)->authorize('delete', $answer);

        $action->execute($answer);

        return ApiResponse::deleted();
    }

    /** POST /v1/answers/{answer}/report — flag an answer for moderation. */
    public function report(Request $request, QuestionAnswer $answer): JsonResponse
    {
        $actor = $this->actor($request);
        Gate::forUser($actor)->authorize('report', $answer);

        $validated = $request->validate([
            'reason' => ['required', Rule::enum(ReportReason::class)],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $answer->report(
            $actor->actorId(),
            ReportReason::from($validated['reason']),
            $validated['note'] ?? null,
        );

        return ApiResponse::success(null, 'Reported.');
    }

    /**
     * The answer's parent question, resolved through the tenant-scoped relation. A cross-tenant (or
     * soft-deleted) question is invisible, so this 404s rather than leaking its existence.
     */
    private function questionForOr404(QuestionAnswer $answer): CourseQuestion
    {
        $question = $answer->question;

        if ($question === null) {
            throw new NotFoundHttpException('Question not found.');
        }

        return $question;
    }
}
