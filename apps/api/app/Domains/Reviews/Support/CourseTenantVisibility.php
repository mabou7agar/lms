<?php

declare(strict_types=1);

namespace App\Domains\Reviews\Support;

use App\Platform\Shared\Tenancy\TenancyBypassPolicy;
use App\Platform\Shared\Tenancy\TenantContext;

/**
 * T1 (Option N) — decides whether a course (identified only by its organization_id scalar) is
 * visible to the ACTIVE tenant under the "global-OR-own-org" rule. Used at the Reviews write/read
 * choke points (resolving a course by public_id before listing/creating reviews) so a scoped actor
 * can never reach another organization's private course.
 *
 * Replicated from App\Domains\Authoring\Support\CourseTenantVisibility (a Domain may not import
 * another Domain's classes). Depends only on the Shared tenancy kernel. Bypass/no-op conditions
 * mirror the tenant scope exactly, so it is a no-op wherever that scope is (no tenant resolved,
 * explicit re-entrant bypass, role-based bypass, or maintenance).
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

        if ($tenantId === null) {
            return true;
        }

        return $courseOrganizationId === null || (string) $courseOrganizationId === $tenantId->toString();
    }
}
