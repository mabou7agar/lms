<?php

declare(strict_types=1);

namespace App\Domains\Qna\Http\Controllers\Api\V1;

use App\Domains\Qna\Enums\QuestionStatus;
use App\Domains\Qna\Models\CourseQuestion;
use App\Domains\Qna\Models\QnaSetting;
use App\Domains\Qna\Services\QnaMetricsService;
use App\Platform\Identity\Contracts\Actor;
use App\Platform\Shared\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The instructor's Q&A queue: every question across the courses they teach, oldest first, with the
 * overdue ones callable out.
 *
 * Scoping is the whole security story here. The course set comes from CourseAccessPort — the single
 * definition of "this instructor owns this course" — and every query is bounded by it, so an
 * instructor cannot reach a question on somebody else's course even by asking for it. An actor who
 * teaches nothing gets an empty queue rather than an error, because having no courses is a normal
 * state, not a failure.
 *
 * Private questions ARE included: the course team is exactly who a private question is addressed to.
 */
final class InstructorQnaController extends QnaController
{
    /** GET /v1/instructor/questions — the queue, filterable by state and course. */
    public function index(Request $request, QnaMetricsService $metrics): JsonResponse
    {
        $actor = $this->actor($request);
        $courseIds = $this->access->manageableCourseIds($actor);

        $validated = $request->validate([
            'filter' => ['nullable', Rule::in(['all', 'unanswered', 'overdue', 'answered'])],
            'course_id' => ['nullable', 'string'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        if ($courseIds === []) {
            return ApiResponse::success([
                'metrics' => $metrics->forCourses([]),
                'questions' => [],
                'meta' => ['total' => 0],
            ]);
        }

        $scoped = $this->applyCourseFilter($courseIds, $validated['course_id'] ?? null, $actor);

        $query = CourseQuestion::query()
            ->with('acceptedAnswer')
            ->whereIn('course_id', $scoped)
            ->where('status', '!=', QuestionStatus::Hidden->value);

        $slaHours = QnaSetting::current()->response_sla_hours;

        match ($validated['filter'] ?? 'unanswered') {
            // Oldest first: the question that has waited longest is the one to answer next.
            'unanswered' => $query->awaitingResponse()->oldest('id'),
            'overdue' => $query->overdue($slaHours)->oldest('id'),
            'answered' => $query->whereNotNull('first_response_at')->latest('first_response_at'),
            default => $query->latest('id'),
        };

        $paginator = $query->paginate($validated['per_page'] ?? 20);
        $authors = $this->authorsFor($paginator->getCollection()->pluck('user_id'));

        $response = $this->paginatedQuestions($paginator, $authors);
        $payload = $response->getData(true);

        // The metrics travel with the queue so the instructor's panel needs one round trip, and they
        // are computed over the SAME course scope the list is bounded by.
        $payload['data'] = [
            'metrics' => $metrics->forCourses($scoped),
            'questions' => $payload['data'] ?? [],
            'meta' => $payload['meta'] ?? ['total' => 0],
        ];

        return ApiResponse::success($payload['data']);
    }

    /**
     * Narrow the queue to one course, but only one the caller already manages — a course_id the
     * instructor does not own resolves to nothing rather than widening the scope.
     *
     * @param  list<int>  $manageable
     * @return list<int>
     */
    private function applyCourseFilter(array $manageable, ?string $coursePublicId, Actor $actor): array
    {
        if ($coursePublicId === null || $coursePublicId === '') {
            return $manageable;
        }

        $id = $this->access->manageableCourseId($actor, $coursePublicId);

        return $id !== null && in_array($id, $manageable, true) ? [$id] : [];
    }
}
