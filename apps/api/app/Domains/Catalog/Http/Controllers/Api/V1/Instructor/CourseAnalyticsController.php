<?php

namespace App\Domains\Catalog\Http\Controllers\Api\V1\Instructor;

use App\Domains\Catalog\Analytics\CourseInsightsService;
use App\Domains\Catalog\Analytics\InstructorScope;
use App\Domains\Catalog\Http\Resources\Instructor\CourseAnalyticsResource;
use App\Domains\Catalog\Models\Course;
use App\Platform\Shared\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CourseAnalyticsController extends InstructorController
{
    /** Window bounds for the inactive-learner recency filter. */
    private const DEFAULT_INACTIVE_DAYS = 14;

    private const MAX_INACTIVE_DAYS = 365;

    /**
     * GET /teach/courses/{course}/analytics — per-course engagement analytics (watch-time, lesson
     * drop-off, inactive learners, completion distribution).
     *
     * Gated exactly like the rest of the instructor analytics surface, in order: portal role, then
     * the `analytics.view` permission, then course ownership. A caller who fails an earlier gate never
     * learns whether a later one would have passed, and a course the caller does not train 404s rather
     * than 403s — the same privacy convention the dashboard uses. Revenue is never surfaced here.
     */
    public function show(
        Request $request,
        Course $course,
        InstructorScope $scope,
        CourseInsightsService $insights,
    ): JsonResponse {
        $instructor = $this->instructor($request);
        $scope->assertMayReadAnalytics($instructor);
        $course = $this->ownedCourse($request, $course);

        $report = $insights->forCourse((int) $course->id, $this->inactiveDays($request));

        return ApiResponse::success(new CourseAnalyticsResource($report));
    }

    /** The inactive-learner window in days: a bounded positive integer, defaulted and clamped. */
    private function inactiveDays(Request $request): int
    {
        $days = $request->integer('inactive_days', self::DEFAULT_INACTIVE_DAYS);

        return max(1, min(self::MAX_INACTIVE_DAYS, $days));
    }
}
