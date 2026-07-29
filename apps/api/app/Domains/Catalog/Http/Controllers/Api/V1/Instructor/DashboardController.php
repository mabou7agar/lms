<?php

namespace App\Domains\Catalog\Http\Controllers\Api\V1\Instructor;

use App\Domains\Catalog\Analytics\CoursePerformanceService;
use App\Domains\Catalog\Analytics\InstructorActivityService;
use App\Domains\Catalog\Analytics\InstructorDashboardService;
use App\Domains\Catalog\Analytics\InstructorScope;
use App\Domains\Catalog\Http\Requests\InstructorDashboardRequest;
use App\Domains\Catalog\Http\Resources\Instructor\DashboardOverviewResource;
use App\Domains\Catalog\Services\InstructorAnalyticsService;
use App\Platform\Shared\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends InstructorController
{
    public function index(Request $request, InstructorAnalyticsService $analytics): JsonResponse
    {
        $instructor = $this->instructor($request);

        return ApiResponse::success($analytics->dashboard($instructor->actorId()));
    }

    /**
     * GET /teach/dashboard/overview — instructor-scoped metrics.
     *
     * Three gates, in order, and the order matters: portal role, then the analytics permission,
     * then course scope. A caller who fails the first never learns whether the second would have
     * passed, and the scope is resolved to a bounded id set BEFORE any aggregate runs — nothing
     * here computes a platform total and narrows it afterwards.
     */
    public function overview(
        InstructorDashboardRequest $request,
        InstructorScope $scope,
        InstructorDashboardService $dashboard,
    ): JsonResponse {
        $instructor = $this->instructor($request);
        $scope->assertMayReadAnalytics($instructor);

        $courseIds = $scope->courseIds($instructor);

        if (($course = $request->courseFilter()) !== null) {
            $courseIds = $scope->narrowToCourse($courseIds, $course);
        }

        $metrics = $dashboard->overview($courseIds, $request->dateFrom(), $request->dateTo());

        return ApiResponse::success(new DashboardOverviewResource($metrics));
    }

    /** GET /teach/dashboard/courses — paginated per-course performance. */
    public function performance(
        InstructorDashboardRequest $request,
        InstructorScope $scope,
        CoursePerformanceService $performance,
    ): JsonResponse {
        $courseIds = $this->scopedCourseIds($request, $scope);

        // The request narrows every filter to a scalar; the raw query bag never reaches the
        // service, so a client sending ?search[]=x cannot hand it an array.
        return ApiResponse::paginated(
            $performance->paginate($courseIds, $request->performanceFilters()),
        );
    }

    /** GET /teach/dashboard/activity — authoring activity from persisted timestamps. */
    public function activity(
        InstructorDashboardRequest $request,
        InstructorScope $scope,
        InstructorActivityService $activity,
    ): JsonResponse {
        return ApiResponse::success(
            $activity->authoringActivity($this->scopedCourseIds($request, $scope)),
        );
    }

    /** GET /teach/dashboard/alerts — actionable issues, each traceable to a real evaluation. */
    public function alerts(
        InstructorDashboardRequest $request,
        InstructorScope $scope,
        InstructorActivityService $activity,
    ): JsonResponse {
        return ApiResponse::success(
            $activity->alerts($this->scopedCourseIds($request, $scope)),
        );
    }

    /**
     * The shared gate for every dashboard read: portal role, analytics permission, course scope —
     * in that order, resolved to a bounded id set before any aggregate runs.
     *
     * @return list<int>
     */
    private function scopedCourseIds(InstructorDashboardRequest $request, InstructorScope $scope): array
    {
        $instructor = $this->instructor($request);
        $scope->assertMayReadAnalytics($instructor);

        $courseIds = $scope->courseIds($instructor);

        if (($course = $request->courseFilter()) !== null) {
            $courseIds = $scope->narrowToCourse($courseIds, $course);
        }

        return $courseIds;
    }
}
