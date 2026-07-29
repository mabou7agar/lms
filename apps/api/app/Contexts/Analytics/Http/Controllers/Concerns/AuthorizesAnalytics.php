<?php

namespace App\Contexts\Analytics\Http\Controllers\Concerns;

use App\Contexts\Analytics\Enums\AnalyticsPermission;
use App\Platform\Identity\Contracts\Actor;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Authorization for the metric-driven analytics endpoints (KPIs, dashboards, report definitions).
 *
 * These three controllers previously carried no authorization beyond `auth:sanctum`, so any
 * authenticated user — including a learner — could read platform-wide figures. The policies to
 * prevent that already existed but were never invoked; this trait is where they finally are.
 *
 * Gated on the `analytics.view` PERMISSION, via Actor::hasPermission() rather than `$user->can()`.
 * `can()` resolves a Spatie guard that Sanctum does not match, so it answers false even for a
 * holder of the permission; hasPermission() pins the `web` guard the permissions are seeded under.
 *
 * SCOPE, and why an instructor is still refused here:
 * `metric_snapshots` rows are written with an empty dimension — there is no course or instructor
 * column populated — so these endpoints can only ever answer platform-wide. An instructor is
 * required to see analytics for their own courses ONLY, and a platform-wide answer cannot satisfy
 * that. Rather than leak other instructors' figures, a non-administrator holding `analytics.view`
 * is refused here with an explanation, and reads their own course analytics from `/api/v1/teach/*`,
 * which is scoped by ownership at the query level.
 *
 * Lifting that restriction is a data-model change, not an authorization change: dimension the
 * rollups by course, then scope the query. Widening the gate alone would be a leak.
 */
trait AuthorizesAnalytics
{
    /** Unit marking a metric as money; see config/analytics.php. */
    protected const MONEY_UNIT = 'currency_minor';

    /**
     * Any analytics read. Throws 403 rather than 404: the endpoints themselves are not secret.
     */
    protected function assertCanViewAnalytics(Request $request): void
    {
        if (! $this->actorHasPermission($request, AnalyticsPermission::ViewAnalytics)) {
            throw new AccessDeniedHttpException('Analytics access required.');
        }

        if (! $this->isAdministrator($request)) {
            throw new AccessDeniedHttpException(
                'These analytics are platform-wide and cannot be scoped to your courses. '
                .'Use the instructor dashboard for analytics on courses you teach.',
            );
        }
    }

    /**
     * Producing a downloadable export.
     *
     * A separate permission from reading, because an export is a durable artifact that leaves the
     * application and can be forwarded. Unlike `assertCanViewAnalytics()` this carries no
     * platform-wide scope refusal: the check is the permission itself, and no role currently holds
     * it except `admin`. If instructor-scoped exports are added later, granting the permission is
     * the whole change — no code here moves.
     */
    protected function assertCanExportAnalytics(Request $request): void
    {
        if (! $this->actorHasPermission($request, AnalyticsPermission::ExportAnalytics)) {
            throw new AccessDeniedHttpException('Analytics export permission required.');
        }
    }

    /**
     * May this caller see currency-denominated metrics?
     *
     * Callers filter on this rather than rejecting the whole request: a dashboard that happens to
     * include a revenue widget should still render its other cards for someone without the
     * permission, not fail outright.
     */
    protected function canViewRevenue(Request $request): bool
    {
        return $this->actorHasPermission($request, AnalyticsPermission::ViewRevenue);
    }

    /** `super_admin` bypasses, matching the `before()` hook on every analytics policy. */
    private function actorHasPermission(Request $request, AnalyticsPermission $permission): bool
    {
        $user = $request->user();

        if (! $user instanceof Actor) {
            return false;
        }

        return $user->hasRole('super_admin') || $user->hasPermission($permission->value);
    }

    private function isAdministrator(Request $request): bool
    {
        $user = $request->user();

        return $user instanceof Actor && $user->hasRole(['admin', 'super_admin']);
    }
}
