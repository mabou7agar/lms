<?php

namespace App\Domains\Catalog\Http\Controllers\Api\V1;

use App\Domains\Catalog\Http\Requests\CourseIndexRequest;
use App\Domains\Catalog\Http\Resources\CourseListResource;
use App\Domains\Catalog\Http\Resources\CourseResource;
use App\Domains\Catalog\Models\Course;
use App\Domains\Catalog\Services\CourseSearchService;
use App\Domains\Catalog\Services\RelatedCoursesService;
use App\Platform\Identity\Contracts\UserLookupPort;
use App\Platform\Shared\Support\ApiResponse;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class CourseController extends Controller
{
    public function index(CourseIndexRequest $request, CourseSearchService $search, UserLookupPort $users): JsonResponse
    {
        $perPage = (int) ($request->validated()['per_page'] ?? config('catalog.pagination.per_page'));

        /** @var \Illuminate\Pagination\LengthAwarePaginator<int, Course> $paginator */
        $paginator = $search->paginate($request->validated(), $perPage);

        // Attach each course's trainers (boundary-safe refs) so listings can show instructor avatars.
        // Resolved in ONE batched port call across the whole page (no N+1); CourseListResource reads it.
        /** @var EloquentCollection<int, Course> $courses */
        $courses = $paginator->getCollection();
        $this->attachTrainerRefs($courses, $users);

        return ApiResponse::paginated($paginator, CourseListResource::class);
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

    public function show(string $publicId, RelatedCoursesService $related): JsonResponse
    {
        if (! Str::isUuid($publicId)) {
            throw new NotFoundHttpException('Course not found.');
        }

        $course = Course::query()
            ->published()
            ->visible()
            ->with(['level', 'language', 'categories', 'tags', 'trainerLinks'])
            ->where('public_id', $publicId)
            ->first();

        if ($course === null) {
            throw new NotFoundHttpException('Course not found.');
        }

        $course->setRelation('related', $related->for($course));

        return ApiResponse::success(new CourseResource($course));
    }
}
