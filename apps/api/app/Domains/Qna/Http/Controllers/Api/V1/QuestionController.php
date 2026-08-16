<?php

declare(strict_types=1);

namespace App\Domains\Qna\Http\Controllers\Api\V1;

use App\Domains\Qna\Actions\AskQuestionAction;
use App\Domains\Qna\Actions\CloseQuestionAction;
use App\Domains\Qna\Actions\DeleteQuestionAction;
use App\Domains\Qna\Actions\PinQuestionAction;
use App\Domains\Qna\Actions\UpdateQuestionAction;
use App\Domains\Qna\Enums\QuestionStatus;
use App\Domains\Qna\Enums\QuestionVisibility;
use App\Domains\Qna\Http\Resources\QuestionDetailResource;
use App\Domains\Qna\Http\Resources\QuestionResource;
use App\Domains\Qna\Models\CourseQuestion;
use App\Domains\Qna\Models\QnaSetting;
use App\Platform\Shared\Moderation\Enums\ReportReason;
use App\Platform\Shared\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Learner + instructor Q&A surface for a course. Every method funnels through the same participation
 * gate (course-scoped reads) or the CourseQuestionPolicy (model-bound writes), so no authenticated
 * non-participant can read or mutate another course's — or another tenant's — questions.
 */
final class QuestionController extends QnaController
{
    /** GET /v1/courses/{course}/questions — paginated, filterable, tenant-scoped. */
    public function index(Request $request, string $course): JsonResponse
    {
        $actor = $this->actor($request);
        $resolved = $this->resolveCourseOr404($course);
        $this->assertParticipation($actor, $resolved->id);

        $validated = $request->validate([
            'status' => ['nullable', Rule::in(array_values(array_filter(
                QuestionStatus::values(),
                static fn (string $v): bool => $v !== QuestionStatus::Hidden->value,
            )))],
            'sort' => ['nullable', Rule::in(['recent', 'pinned', 'unanswered', 'overdue'])],
            'search' => ['nullable', 'string', 'max:200'],
            'lesson_id' => ['nullable', 'string'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $query = CourseQuestion::query()
            ->with('acceptedAnswer')
            ->where('course_id', $resolved->id)
            ->visible(); // moderation-hidden questions never surface in the learner listing

        // A private thread belongs to its author and the course team. Staff see the whole course so
        // they can actually answer; everyone else sees the public threads plus their own.
        if (! $this->canManageCourse($actor, $resolved->id)) {
            $query->readableBy($actor->actorId());
        }

        if (isset($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        $lessonId = $this->resolveLessonIdInCourse($validated['lesson_id'] ?? null, $resolved->id);
        if (($validated['lesson_id'] ?? null) !== null && $lessonId === null) {
            // An unknown / cross-course lesson filter yields an empty page, never a leak.
            $query->whereRaw('1 = 0');
        } elseif ($lessonId !== null) {
            $query->where('lesson_id', $lessonId);
        }

        if (isset($validated['search']) && $validated['search'] !== '') {
            $term = '%'.$validated['search'].'%';
            $query->where(function ($q) use ($term): void {
                $q->where('title', 'like', $term)->orWhere('body', 'like', $term);
            });
        }

        match ($validated['sort'] ?? 'recent') {
            // "Unanswered" means what it has always meant on the LEARNER-facing board: no replies at
            // all, which is what somebody browsing for a question they could help with is looking
            // for. The instructor queue and the SLA use a stricter sense — no reply from the course
            // TEAM — and that is `overdue` here and awaitingResponse() there. The two audiences ask
            // genuinely different questions, so the same word is not made to serve both.
            'unanswered' => $query->where('answers_count', 0)->latest('id'),
            // Oldest-first: the question that has been waiting longest is the one that should be
            // answered next, which is the opposite of a social feed's ordering.
            'overdue' => $query->overdue($this->slaHours())->oldest('id'),
            'pinned' => $query->orderByRaw('pinned_at IS NULL')->latest('pinned_at')->latest('id'),
            default => $query->latest('id'),
        };

        $paginator = $query->paginate($validated['per_page'] ?? 15);

        $authors = $this->authorsFor($paginator->getCollection()->pluck('user_id'));

        return $this->paginatedQuestions($paginator, $authors);
    }

    /** POST /v1/courses/{course}/questions — ask a question (enrollment enforced in the action). */
    public function store(Request $request, string $course, AskQuestionAction $action): JsonResponse
    {
        $actor = $this->actor($request);
        $resolved = $this->resolveCourseOr404($course);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:10000'],
            'lesson_id' => ['nullable', 'string'],
            'lesson_timestamp_seconds' => ['nullable', 'integer', 'min:0'],
            'visibility' => ['nullable', Rule::in(QuestionVisibility::values())],
        ]);

        $question = $action->execute($actor, $resolved, [
            'title' => $validated['title'],
            'body' => $validated['body'],
            'lesson_id' => $this->resolveLessonIdInCourse($validated['lesson_id'] ?? null, $resolved->id),
            'lesson_timestamp_seconds' => $validated['lesson_timestamp_seconds'] ?? null,
            'visibility' => $validated['visibility'] ?? QuestionVisibility::Public->value,
        ]);

        $authors = $this->authorsFor([$actor->actorId()]);

        return ApiResponse::created(new QuestionResource($question, $authors[$actor->actorId()] ?? null));
    }

    /** GET /v1/questions/{question} — the full thread with answers (accepted first). */
    public function show(Request $request, CourseQuestion $question): JsonResponse
    {
        $actor = $this->actor($request);
        Gate::forUser($actor)->authorize('view', $question);

        $question->load('acceptedAnswer');

        $answers = $question->answers()
            ->orderByDesc('accepted')
            ->oldest('id')
            ->get();

        $authorIds = $answers->pluck('user_id')->push($question->user_id);

        return ApiResponse::success(
            new QuestionDetailResource($question, $answers, $this->authorsFor($authorIds)),
        );
    }

    /** PATCH /v1/questions/{question} — edit your own question. */
    public function update(Request $request, CourseQuestion $question, UpdateQuestionAction $action): JsonResponse
    {
        $actor = $this->actor($request);
        Gate::forUser($actor)->authorize('update', $question);

        $validated = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:200'],
            'body' => ['sometimes', 'required', 'string', 'max:10000'],
            'lesson_timestamp_seconds' => ['sometimes', 'nullable', 'integer', 'min:0'],
        ]);

        $question = $action->execute($question, $validated);

        $authors = $this->authorsFor([$question->user_id]);

        return ApiResponse::updated(new QuestionResource($question, $authors[(int) $question->user_id] ?? null));
    }

    /** DELETE /v1/questions/{question} — soft-delete your own question. */
    public function destroy(Request $request, CourseQuestion $question, DeleteQuestionAction $action): JsonResponse
    {
        $actor = $this->actor($request);
        Gate::forUser($actor)->authorize('delete', $question);

        $action->execute($question);

        return ApiResponse::deleted();
    }

    /** POST /v1/questions/{question}/pin — instructor pins a question. */
    public function pin(Request $request, CourseQuestion $question, PinQuestionAction $action): JsonResponse
    {
        $actor = $this->actor($request);
        Gate::forUser($actor)->authorize('pin', $question);

        $question = $action->execute($question, true);

        $authors = $this->authorsFor([$question->user_id]);

        return ApiResponse::updated(new QuestionResource($question, $authors[(int) $question->user_id] ?? null));
    }

    /** DELETE /v1/questions/{question}/pin — instructor unpins a question. */
    public function unpin(Request $request, CourseQuestion $question, PinQuestionAction $action): JsonResponse
    {
        $actor = $this->actor($request);
        Gate::forUser($actor)->authorize('pin', $question);

        $question = $action->execute($question, false);

        $authors = $this->authorsFor([$question->user_id]);

        return ApiResponse::updated(new QuestionResource($question, $authors[(int) $question->user_id] ?? null));
    }

    /** POST /v1/questions/{question}/report — flag a question for moderation. */
    public function report(Request $request, CourseQuestion $question): JsonResponse
    {
        $actor = $this->actor($request);
        Gate::forUser($actor)->authorize('report', $question);

        $validated = $request->validate([
            'reason' => ['required', Rule::enum(ReportReason::class)],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $question->report(
            $actor->actorId(),
            ReportReason::from($validated['reason']),
            $validated['note'] ?? null,
        );

        return ApiResponse::success(null, 'Reported.');
    }

    /**
     * POST /v1/questions/{question}/close — the course team ends a thread that will not be resolved:
     * a duplicate, an off-topic question, or one a course update has overtaken. Distinct from
     * accepting an answer, which is the asker saying their problem went away.
     */
    public function close(Request $request, CourseQuestion $question, CloseQuestionAction $action): JsonResponse
    {
        $actor = $this->actor($request);
        Gate::forUser($actor)->authorize('moderateThread', $question);

        $question = $action->close($question);

        $authors = $this->authorsFor([$question->user_id]);

        return ApiResponse::updated(new QuestionResource($question, $authors[(int) $question->user_id] ?? null));
    }

    /** DELETE /v1/questions/{question}/close — reopen a thread that was closed by mistake. */
    public function reopen(Request $request, CourseQuestion $question, CloseQuestionAction $action): JsonResponse
    {
        $actor = $this->actor($request);
        Gate::forUser($actor)->authorize('moderateThread', $question);

        // The action returns the thread to whichever state it had actually reached before closing.
        $question = $action->reopen($question);

        $authors = $this->authorsFor([$question->user_id]);

        return ApiResponse::updated(new QuestionResource($question, $authors[(int) $question->user_id] ?? null));
    }

    /** The admin-configured response promise, in calendar hours. */
    private function slaHours(): int
    {
        return QnaSetting::current()->response_sla_hours;
    }
}
