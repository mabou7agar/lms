<?php

namespace App\Domains\Catalog\Http\Controllers\Api\V1;

use App\Domains\Catalog\Http\Requests\CourseIndexRequest;
use App\Domains\Catalog\Http\Resources\CourseListResource;
use App\Domains\Catalog\Http\Resources\CourseResource;
use App\Domains\Catalog\Models\Course;
use App\Domains\Catalog\Services\CourseSearchService;
use App\Domains\Catalog\Services\PublicCourseDetailsService;
use App\Platform\Identity\Contracts\UserLookupPort;
use App\Platform\Shared\Commerce\Contracts\PurchaseSummaryPort;
use App\Platform\Shared\Support\ApiResponse;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class CourseController extends Controller
{
    public function index(CourseIndexRequest $request, CourseSearchService $search, UserLookupPort $users, PurchaseSummaryPort $purchases): JsonResponse
    {
        $perPage = (int) ($request->validated()['per_page'] ?? config('catalog.pagination.per_page'));

        /** @var LengthAwarePaginator<int, Course> $paginator */
        $paginator = $search->paginate($request->validated(), $perPage);

        // Attach each course's trainers (boundary-safe refs) so listings can show instructor avatars.
        // Resolved in ONE batched port call across the whole page (no N+1); CourseListResource reads it.
        /** @var EloquentCollection<int, Course> $courses */
        $courses = $paginator->getCollection();
        $this->attachTrainerRefs($courses, $users);
        $this->attachPurchaseSummaries($courses, $purchases);

        return ApiResponse::paginated($paginator, CourseListResource::class);
    }

    /**
     * Stash how each course is sold, resolved in ONE batched port call for the whole page. Catalog
     * never touches a Commerce model — only the Shared port and its DTO.
     *
     * @param  EloquentCollection<int, Course>  $courses
     */
    private function attachPurchaseSummaries(EloquentCollection $courses, PurchaseSummaryPort $purchases): void
    {
        if ($courses->isEmpty()) {
            return;
        }

        $summaries = $purchases->forCourseIds(
            $courses->map(fn (Course $c): int => (int) $c->id)->all(),
        );

        foreach ($courses as $course) {
            $course->setAttribute('purchase_summary', $summaries[(int) $course->id] ?? null);
        }
    }

    /**
     * Eager-load trainer links, batch-resolve to UserRefs once, and stash them per course.
     *
     * @param  EloquentCollection<int, Course>  $courses
     */
    private function attachTrainerRefs(EloquentCollection $courses, UserLookupPort $users): void
    {
        if ($courses->isEmpty()) {
            return;
        }

        $courses->loadMissing('trainerLinks');

        $ids = $courses
            ->flatMap(fn ($course) => $course->trainerLinks->pluck('user_id'))
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        $refs = $ids === [] ? [] : $users->refsByIds($ids); // [userId => UserRef], batched.

        foreach ($courses as $course) {
            $course->setAttribute(
                'trainer_refs',
                $course->trainerLinks
                    ->map(fn ($link) => $refs[(int) $link->user_id] ?? null)
                    ->filter()
                    ->values()
                    ->all(),
            );
        }
    }

    public function show(string $publicId, PublicCourseDetailsService $details): JsonResponse
    {
        $course = $details->find($publicId);

        if ($course === null) {
            throw new NotFoundHttpException('Course not found.');
        }

        return ApiResponse::success(new CourseResource($course));
    }
}
