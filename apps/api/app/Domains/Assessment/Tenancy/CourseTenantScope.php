<?php

declare(strict_types=1);

namespace App\Domains\Assessment\Tenancy;

use App\Platform\Shared\Tenancy\TenancyBypassPolicy;
use App\Platform\Shared\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Global scope that gives a course-anchored Assessment-context model (Assessment, Assignment) the
 * T1 "global-OR-own-org" (Option N) tenancy of its owning Course — WITHOUT storing a tenant column
 * on the assessment/assignment tables or on any of their children (questions, options, rubrics,
 * criteria, levels). Tenancy is DERIVED by joining to `courses`, exactly as the T1 matrix prescribes
 * for transitive shared-or-owned content ("enforce ... a scope that joins to the course").
 *
 * When a tenant is resolved, a row is visible only when:
 *   - it is a platform-bank row (course_id IS NULL — a course-less admin bank), OR
 *   - its owning course is GLOBAL (courses.organization_id IS NULL — the public catalog), OR
 *   - its owning course is PRIVATE to the active tenant (courses.organization_id = active tenant).
 * Another organization's private course — and therefore its assessments/assignments, and transitively
 * the questions/options/rubrics reached through them — is never visible.
 *
 * Filtering conditions mirror the kernel scopes exactly (bypass depth, RoleBasedTenancyBypassPolicy,
 * maintenance, and no-tenant => no filter), so it is backward compatible: unauthenticated / public /
 * console / queue contexts and NULL-organization users see everything, and the existing suite is
 * behaviourally unchanged. The `courses.organization_id` column is only referenced when a tenant is
 * actually resolved, so the clause is never emitted for the existing NULL-org test corpus.
 *
 * References the `courses` TABLE by name only (a string) — never Catalog's Course model — so it adds
 * no cross-context class dependency (Deptrac/ArchitectureTest safe), matching how the rest of this
 * context treats `course_id` as an opaque scalar foreign key.
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
