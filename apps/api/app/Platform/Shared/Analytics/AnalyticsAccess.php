<?php

namespace App\Platform\Shared\Analytics;

/**
 * Permission slugs for reading analytics, defined once in Shared.
 *
 * Analytics owns the capability, but the instructor dashboard lives in Catalog, and a bounded
 * context may not depend on another context — only on Shared. Without this, Catalog would either
 * import `Contexts\Analytics\Enums\AnalyticsPermission` (a Deptrac violation) or repeat the string
 * literal (two slugs that drift apart the first time one is renamed).
 *
 * `AnalyticsPermission` derives its case values from these constants, so there is exactly one
 * definition of each slug in the codebase and the enum remains the canonical thing to reference
 * from inside the Analytics context.
 */
final class AnalyticsAccess
{
    /** Read analytics. Held by admin and instructor; scope is enforced separately per surface. */
    public const VIEW = 'analytics.view';

    /** Manage saved report definitions. */
    public const MANAGE_REPORTS = 'analytics.reports.manage';

    /** Produce a downloadable export — a stronger capability than reading, granted separately. */
    public const EXPORT = 'analytics.export';

    /** See currency-denominated metrics. Administrators only. */
    public const VIEW_REVENUE = 'analytics.revenue.view';
}
