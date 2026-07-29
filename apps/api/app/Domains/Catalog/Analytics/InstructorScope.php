<?php

namespace App\Domains\Catalog\Analytics;

use App\Domains\Catalog\Models\Course;
use App\Platform\Identity\Contracts\Actor;
use App\Platform\Shared\Analytics\AnalyticsAccess;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The single place that answers "which courses may this principal see analytics for".
 *
 * Every instructor analytics query starts from `courseIds()`. Nothing filters a platform-wide
 * aggregate in PHP afterwards — the scope is a bounded id set fed into the WHERE clause, so a
 * course belonging to another instructor cannot reach the result at any stage.
 *
 * Centralized deliberately: ownership logic duplicated across controllers is how one endpoint ends
 * up with a subtly weaker rule than its neighbours.
 */
class InstructorScope
{
    /**
     * Reading instructor analytics requires the `analytics.view` permission — checked with
     * hasPermission(), not can(), because the latter resolves the request's guard and answers false
     * under Sanctum for a genuine holder.
     *
     * super_admin bypasses because the seeders grant it no permissions by design. Admins hold the
     * permission outright, so they need no role branch here.
     */
    public function assertMayReadAnalytics(Actor $user): void
    {
        if ($user->hasRole('super_admin')) {
            return;
        }

        // The slug comes from Shared, not from the Analytics enum: a bounded context may not depend
        // on another context, and repeating the literal would create a second slug to keep in sync.
        if (! $user->hasPermission(AnalyticsAccess::VIEW)) {
            throw new AccessDeniedHttpException('Analytics access required.');
        }
    }

    /**
     * Internal course ids this principal may aggregate over.
     *
     * Admins and super_admins see every course; an instructor sees only those they train. An empty
     * array is a legitimate answer (a new instructor) and callers must treat it as "no data", never
     * as "no filter" — passing an empty set to whereIn is what turns a scoped query into a
     * platform-wide one.
     *
     * @return list<int>
     */
    public function courseIds(Actor $user): array
    {
        $query = Course::query();

        if (! $user->hasRole(['super_admin', 'admin'])) {
            $query->forTrainer($user->actorId());
        }

        /** @var list<int> $ids */
        $ids = $query->pluck('id')->map(static fn ($id): int => (int) $id)->all();

        return $ids;
    }

    /**
     * Narrow the scope to one course, verifying the principal may see it.
     *
     * Returns 404 rather than 403 for a course the caller does not train. That is the established
     * convention in this portal (see InstructorController::ownedCourse) and it is the stronger
     * privacy position: a 403 confirms the course exists, turning the endpoint into an oracle for
     * probing other instructors' catalogues.
     *
     * @param  list<int>  $scope  the caller's full accessible set, to avoid re-querying
     * @return list<int> a single-element scope
     */
    public function narrowToCourse(array $scope, string $coursePublicId): array
    {
        $course = Course::query()->where('public_id', $coursePublicId)->first(['id']);

        if ($course === null || ! in_array((int) $course->getKey(), $scope, true)) {
            throw new NotFoundHttpException('Course not found.');
        }

        return [(int) $course->getKey()];
    }
}
