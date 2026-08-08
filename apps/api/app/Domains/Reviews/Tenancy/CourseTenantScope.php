<?php

declare(strict_types=1);

namespace App\Domains\Reviews\Tenancy;

use App\Platform\Shared\Tenancy\TenancyBypassPolicy;
use App\Platform\Shared\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Global scope giving a course-anchored Reviews model (CourseReview) the T1 "global-OR-own-org"
 * (Option N) tenancy of its owning Course — WITHOUT relying on any tenant column on the reviews
 * table. Tenancy is DERIVED by joining to `courses`, exactly as the T1 matrix prescribes for
 * transitive shared-or-owned content.
 *
 * Replicated from App\Domains\Assessment\Tenancy\CourseTenantScope (a Domain may not import another
 * Domain's classes). It references the `courses` TABLE by name only (a string) — never Catalog's
 * Course model — so it adds no cross-context class dependency (Deptrac/ArchitectureTest safe).
 *
 * When a tenant is resolved, a review row is visible only when its owning course is GLOBAL
 * (courses.organization_id IS NULL) OR PRIVATE to the active tenant (courses.organization_id =
 * active tenant). Another organization's private course — and therefore its reviews — is never
 * visible. Bypass/no-op conditions mirror the kernel scopes exactly, so unauthenticated / public /
 * console / NULL-org contexts see everything (backward compatible).
 *
 * Removable per query via `Model::withoutGlobalScope(CourseTenantScope::class)`.
 */
final class CourseTenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        /** @var TenantContext $context */
        $context = app(TenantContext::class);

        if ($context->isBypassed()) {
            return;
        }

        /** @var TenancyBypassPolicy $bypassPolicy */
        $bypassPolicy = app(TenancyBypassPolicy::class);
        if ($bypassPolicy->shouldBypassTenancy()) {
            return;
        }

        if (app()->isDownForMaintenance()) {
            return;
        }

        $tenantId = $context->id();

        if ($tenantId === null) {
            return;
        }

        $courseColumn = $model->qualifyColumn('course_id');
        $tenantValue = $tenantId->value;

        // Wrapped in a closure so the OR never widens an adjacent clause elsewhere in the query.
        $builder->where(function (Builder $query) use ($courseColumn, $tenantValue): void {
            $query->whereNull($courseColumn)
                ->orWhereExists(function (QueryBuilder $sub) use ($courseColumn, $tenantValue): void {
                    $sub->from('courses')
                        ->whereColumn('courses.id', $courseColumn)
                        ->whereNull('courses.deleted_at')
                        ->where(function (QueryBuilder $course) use ($tenantValue): void {
                            $course->whereNull('courses.organization_id')
                                ->orWhere('courses.organization_id', $tenantValue);
                        });
                });
        });
    }
}
