<?php

declare(strict_types=1);

namespace App\Domains\Authoring\Support;

use App\Platform\Shared\Tenancy\TenancyBypassPolicy;
use App\Platform\Shared\Tenancy\TenantContext;

/**
 * T1 (Option N) — decides whether a Catalog course is visible to the ACTIVE tenant under the
 * "global-OR-own-org" rule, so Authoring can enforce the tenant boundary TRANSITIVELY through the
 * parent course at its single authorization choke point (the authoring.manage-curriculum gate).
 * Curriculum children (sections/lessons/blocks/modules/content_versions) therefore inherit the
 * boundary from their course and carry NO redundant tenant column of their own.
 *
 * A resolved tenant may reach a course that is GLOBAL (organization_id IS NULL — the public
 * platform catalog) OR its OWN org-private course (organization_id = active tenant) — and NEVER
 * another organization's private course. The bypass / no-op conditions mirror
 * SharedOrOwnedTenantScope exactly, so this is a no-op wherever the scope itself is: no tenant
 * resolved, explicit re-entrant bypass, role-based bypass (super_admin/admin), or maintenance.
 * That parity is precisely why the existing NULL-org test suite is unaffected — with no tenant
 * resolved every course is treated as reachable, exactly as today.
 *
 * Robust before the shared Catalog wiring lands: if Course has not yet adopted
 * BelongsToTenantNullable, the trait method is absent and the enforcement falls back to reading the
 * `organization_id` column directly. If even the column is absent the read yields NULL and the
 * course is treated as GLOBAL — identical to today's behaviour. Enforcement thus needs only the
 * Catalog `organization_id` column to be effective; the trait adds the query-level scope on top.
 */
final class CourseTenantVisibility
{
    /**
     * Decide visibility from the parent course's tenant id (a plain scalar — the caller reads
     * `$course->getAttribute('organization_id')`), so this helper carries NO dependency on the Catalog
     * Course model and stays inside the Authoring layer's allowed dependencies.
     */
    public static function visible(int|string|null $courseOrganizationId): bool
    {
        $context = app(TenantContext::class);

        // Explicit re-entrant bypass (system jobs / maintenance tasks / Administration).
        if ($context->isBypassed()) {
            return true;
        }

        // Role-based bypass (super_admin/admin) — identical seam the scope consults.
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
