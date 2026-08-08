<?php

namespace App\Domains\Reviews\Http\Controllers\Api\V1;

use App\Domains\Reviews\Actions\CreateReviewAction;
use App\Domains\Reviews\Actions\DeleteReviewAction;
use App\Domains\Reviews\Actions\RespondToReviewAction;
use App\Domains\Reviews\Actions\ToggleHelpfulAction;
use App\Domains\Reviews\Actions\UpdateReviewAction;
use App\Domains\Reviews\Enums\ReviewStatus;
use App\Domains\Reviews\Http\Resources\CourseReviewAggregateResource;
use App\Domains\Reviews\Http\Resources\CourseReviewResource;
use App\Domains\Reviews\Models\CourseReview;
use App\Domains\Reviews\Services\ReviewAggregateService;
use App\Domains\Reviews\Support\CourseLookup;
use App\Domains\Reviews\Support\CourseTenantVisibility;
use App\Platform\Identity\Contracts\Actor;
use App\Platform\Shared\Moderation\Enums\ReportReason;
use App\Platform\Shared\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Course reviews API. The index is public (unauthenticated); every write requires auth:sanctum and
 * is authorized by CourseReviewPolicy / the domain actions. The API envelope is the shared
 * ApiResponse. Course tenancy is honoured both when resolving the course by public_id (index/store)
 * and, for review-scoped writes, by route-model binding (CourseReview's global tenant scope makes
 * another tenant's review a 404).
 */
class CourseReviewController
{
    /** GET /api/v1/courses/{course}/reviews — public, paginated, with aggregate + distribution. */
    public function index(Request $request, string $course, CourseLookup $courses, ReviewAggregateService $aggregates): JsonResponse
    {
        $row = $courses->byPublicId($course);

        if ($row === null || ! CourseTenantVisibility::visible($row->organization_id)) {
            throw new NotFoundHttpException('Course not found.');
        }

        $courseId = (int) $row->id;
        $perPage = min(50, max(5, (int) $request->integer('per_page', 15)));

        $query = CourseReview::query()
            ->where('course_id', $courseId)
            ->where('status', ReviewStatus::Published->value);

        $this->applySort($query, (string) $request->query('sort', 'recent'));

        $reviews = $query->paginate($perPage);
        $aggregate = $aggregates->forCourse($courseId);

        return ApiResponse::success(
            CourseReviewResource::collection($reviews->getCollection()),
            meta: [
                'aggregate' => (new CourseReviewAggregateResource($aggregate))->toArray($request),
                'pagination' => [
                    'current_page' => $reviews->currentPage(),
                    'per_page' => $reviews->perPage(),
                    'total' => $reviews->total(),
                    'last_page' => $reviews->lastPage(),
                ],
            ],
        );
    }

    /** POST /api/v1/courses/{course}/reviews — create the caller's review. */
    public function store(Request $request, string $course, CreateReviewAction $action): JsonResponse
    {
        $actor = $this->actor($request);

        $data = $request->validate([
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'body' => ['nullable', 'string', 'max:5000'],
        ]);

        $review = $action->execute($actor, $course, (int) $data['rating'], $data['body'] ?? null);

        return ApiResponse::created(new CourseReviewResource($review));
    }

    /** PATCH /api/v1/reviews/{review} — update the caller's own review. */
    public function update(Request $request, CourseReview $review, UpdateReviewAction $action): JsonResponse
    {
        $actor = $this->actor($request);
        Gate::forUser($actor)->authorize('update', $review);

        $data = $request->validate([
            'rating' => ['sometimes', 'integer', 'min:1', 'max:5'],
            'body' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ]);

        $review = $action->execute($review, $data);

        return ApiResponse::updated(new CourseReviewResource($review));
    }

    /** DELETE /api/v1/reviews/{review} — delete own review (or moderator). */
    public function destroy(Request $request, CourseReview $review, DeleteReviewAction $action): JsonResponse
    {
        $actor = $this->actor($request);
        Gate::forUser($actor)->authorize('delete', $review);

        $action->execute($review);

        return ApiResponse::deleted();
    }

    /** POST /api/v1/reviews/{review}/helpful — mark the review helpful (idempotent). */
    public function helpful(Request $request, CourseReview $review, ToggleHelpfulAction $action): JsonResponse
    {
        $actor = $this->actor($request);

        $count = $action->execute($actor, $review);

        return ApiResponse::success(['helpful_count' => $count]);
    }

    /** POST /api/v1/reviews/{review}/report — report the review into the moderation queue. */
    public function report(Request $request, CourseReview $review): JsonResponse
    {
        $actor = $this->actor($request);

        $data = $request->validate([
            'reason' => ['required', 'string', Rule::in(ReportReason::values())],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $reportRecord = $review->report($actor->actorId(), ReportReason::from($data['reason']), $data['note'] ?? null);

        return ApiResponse::created([
            'id' => $reportRecord->public_id,
            'status' => $reportRecord->status->value,
        ], 'Report submitted.');
    }

    /** POST /api/v1/reviews/{review}/respond — the course instructor's public response. */
    public function respond(Request $request, CourseReview $review, RespondToReviewAction $action): JsonResponse
    {
        $actor = $this->actor($request);
        Gate::forUser($actor)->authorize('respond', $review);

        $data = $request->validate([
            'response' => ['required', 'string', 'max:5000'],
        ]);

        $review = $action->execute($actor, $review, $data['response']);

        return ApiResponse::updated(new CourseReviewResource($review));
    }

    /** Resolve the authenticated principal as an Actor, or 403. */
    private function actor(Request $request): Actor
    {
        $user = $request->user();

        if (! $user instanceof Actor) {
            throw new AccessDeniedHttpException('Authentication required.');
        }

        return $user;
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<CourseReview>  $query
     */
    private function applySort($query, string $sort): void
    {
        match ($sort) {
            'helpful' => $query->orderByDesc('helpful_count')->orderByDesc('id'),
            'rating' => $query->orderByDesc('rating')->orderByDesc('id'),
            default => $query->orderByDesc('created_at')->orderByDesc('id'),
        };
    }
}
