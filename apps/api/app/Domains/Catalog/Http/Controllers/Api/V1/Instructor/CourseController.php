<?php

namespace App\Domains\Catalog\Http\Controllers\Api\V1\Instructor;

use App\Domains\Catalog\Actions\Course\ArchiveCourseAction;
use App\Domains\Catalog\Actions\Course\PublishCourseAction;
use App\Domains\Catalog\Actions\Course\UnpublishCourseAction;
use App\Domains\Catalog\Contracts\CoursePublishGuard;
use App\Domains\Catalog\Enums\CourseStatus;
use App\Domains\Catalog\Exceptions\CoursePublishBlockedException;
use App\Domains\Catalog\Http\Resources\Instructor\InstructorCourseResource;
use App\Domains\Catalog\Http\Resources\Instructor\ReadinessReportResource;
use App\Domains\Catalog\Models\Course;
use App\Domains\Catalog\Services\InstructorAnalyticsService;
use App\Platform\Shared\Publishing\Data\ChangeSummary;
use App\Platform\Shared\Publishing\Data\CourseReadinessInput;
use App\Platform\Shared\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CourseController extends InstructorController
{
    /** GET /teach/courses?status=draft|published|archived — the caller's courses with stats. */
    public function index(Request $request, InstructorAnalyticsService $analytics): JsonResponse
    {
        $instructor = $this->instructor($request);

        $query = Course::query()->forTrainer($instructor->actorId());

        $status = $request->query('status');
        if (is_string($status) && in_array($status, CourseStatus::values(), true)) {
            $query->where('status', $status);
        }

        // Paginated: an instructor's course list is unbounded, and the per-course curriculum read in
        // courseStatsForCourses is O(courses) — paginating bounds both the payload and that work to a
        // single page. The `data` envelope stays an array of the same resource shape (meta/links
        // additive), so existing consumers are unaffected.
        $paginator = $query->latest('id')
            ->paginate(max(1, min((int) $request->integer('per_page', 20), 100)))
            ->withQueryString();
        $courses = $paginator->getCollection();

        // H3: batch the per-course analytics (enrollment aggregates + quiz pass rates) into two
        // queries for the page instead of ~3 per course. The payload each course receives is
        // identical to the previous courseStats($c) call, so the response shape is unchanged.
        $stats = $analytics->courseStatsForCourses($courses);
        $courses->each(fn (Course $c) => $c->setAttribute('stats_payload', $stats[(int) $c->getKey()] ?? []));

        return ApiResponse::paginated($paginator, InstructorCourseResource::class);
    }

    /** GET /teach/courses/{course} — detail + analytics (404 if not the caller's). */
    public function show(Request $request, Course $course, InstructorAnalyticsService $analytics): JsonResponse
    {
        $course = $this->ownedCourse($request, $course);
        $course->setAttribute('stats_payload', $analytics->courseStats($course));

        return ApiResponse::success(new InstructorCourseResource($course));
    }

    /**
     * GET /teach/courses/{course}/readiness — the explainable evaluation behind publishing.
     *
     * Reads the same guard the publish endpoint enforces, so the panel cannot tell an author their
     * course is ready and then have the publish refused.
     */
    public function readiness(Request $request, Course $course, CoursePublishGuard $guard): JsonResponse
    {
        $course = $this->ownedCourse($request, $course);

        // Catalog owns Course, so Catalog does the flattening — the evaluating domain is not
        // allowed to reach in and read it.
        // getAttribute() rather than property access: Course carries no @property annotations yet,
        // and adding them here would unmatch baseline entries across unrelated files.
        $report = $guard->report(new CourseReadinessInput(
            courseId: (int) $course->getKey(),
            coursePublicId: (string) $course->getAttribute('public_id'),
            description: $course->getAttribute('description'),
            thumbnailPath: $course->getAttribute('thumbnail_path'),
            hasInstructor: $course->trainerLinks()->exists(),
            visibility: $course->getAttribute('visibility')?->value,
        ));

        return ApiResponse::success(new ReadinessReportResource($report));
    }

    /**
     * GET /teach/courses/{course}/changes — draft-vs-published change summary.
     *
     * Always reports unavailable today: no published snapshot is persisted, so there is nothing to
     * compare against. See ChangeSummary for why fabricating one from timestamps would be worse
     * than saying so. The endpoint exists now so the contract is fixed and the frontend needs no
     * change when snapshots land.
     */
    public function changes(Request $request, Course $course): JsonResponse
    {
        $this->ownedCourse($request, $course);

        return ApiResponse::success(ChangeSummary::noBaseline()->toArray());
    }

    public function publish(Request $request, Course $course, PublishCourseAction $action, InstructorAnalyticsService $analytics): JsonResponse
    {
        $course = $this->ownedCourse($request, $course);

        try {
            $course = $action->execute($course);
        } catch (CoursePublishBlockedException $e) {
            return ApiResponse::error($e->errorCode(), $e->getMessage(), $e->details(), 422);
        }

        return $this->courseWithStats($course, $analytics);
    }

    public function unpublish(Request $request, Course $course, UnpublishCourseAction $action, InstructorAnalyticsService $analytics): JsonResponse
    {
        $course = $action->execute($this->ownedCourse($request, $course));

        return $this->courseWithStats($course, $analytics);
    }

    public function archive(Request $request, Course $course, ArchiveCourseAction $action, InstructorAnalyticsService $analytics): JsonResponse
    {
        $course = $action->execute($this->ownedCourse($request, $course));

        return $this->courseWithStats($course, $analytics);
    }

    private function courseWithStats(Course $course, InstructorAnalyticsService $analytics): JsonResponse
    {
        $course->setAttribute('stats_payload', $analytics->courseStats($course));

        return ApiResponse::success(new InstructorCourseResource($course));
    }
}
