<?php

declare(strict_types=1);

namespace App\Domains\Forum\Support;

use App\Platform\Shared\Tenancy\TenancyBypassPolicy;
use App\Platform\Shared\Tenancy\TenantContext;

/**
 * T1 (Option N) — decides whether a course is reachable by the ACTIVE tenant under the
 * "global-OR-own-org" rule, so a WRITE path (creating a thread) can reject an attempt to post into
 * another organization's private course BEFORE persisting. Read isolation is already handled by
 * {@see \App\Domains\Forum\Tenancy\CourseTenantScope}; this covers the create path, which resolves a
 * course id first and must not stamp a thread onto a course the caller cannot see.
 *
 * The bypass / no-op conditions mirror the scope exactly, so it is a no-op wherever the scope is: no
 * tenant resolved, explicit re-entrant bypass, role-based bypass (super_admin/admin), or maintenance.
 *
 * Takes a plain scalar `organization_id` (read by the caller from the `courses` row) so this helper
 * carries NO dependency on Catalog's Course model. Replicated from Authoring's CourseTenantVisibility.
 */
final class CourseTenantVisibility
{
    public static function visible(int|string|null $courseOrganizationId): bool
    {
        $context = app(TenantContext::class);

        if ($context->isBypassed()) {
            return true;
        }

        if (app(TenancyBypassPolicy::class)->shouldBypassTenancy()) {
            return true;
        }

        if (app()->isDownForMaintenance()) {
            return true;
        }

        $tenantId = $context->id();

        // No active tenant (public/console/queue/legacy NULL-org user) => unscoped, backward compatible.
        if ($tenantId === null) {
            return true;
        }

        // Global (NULL) OR owned by the active tenant; never another organization's private course.
        return $courseOrganizationId === null || (string) $courseOrganizationId === $tenantId->toString();
    }
}
