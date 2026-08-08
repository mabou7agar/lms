<?php

declare(strict_types=1);

namespace App\Domains\Qna\Tenancy;

use App\Platform\Shared\Tenancy\TenancyBypassPolicy;
use App\Platform\Shared\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Global scope that gives a course-anchored Q&A model (CourseQuestion) the T1 "global-OR-own-org"
 * (Option N) tenancy of its owning Course — WITHOUT reading a tenant column on the questions table
 * (or on answers, which inherit transitively because every read reaches an answer through its
 * tenant-scoped question). Tenancy is DERIVED by joining to `courses`, exactly as the T1 matrix
 * prescribes for transitive shared-or-owned content.
 *
 * When a tenant is resolved, a row is visible only when its owning course is GLOBAL
 * (courses.organization_id IS NULL — the public catalog) or PRIVATE to the active tenant. Another
 * organization's private course — and therefore its questions/answers — is never visible.
 *
 * Filtering conditions mirror the kernel scopes exactly (bypass depth, RoleBasedTenancyBypassPolicy,
 * maintenance, and no-tenant => no filter), so it is backward compatible: unauthenticated / console /
 * queue contexts and NULL-organization users see everything, and the existing suite is unchanged.
 *
 * References the `courses` TABLE by name only (a string) — never Catalog's Course model — so it adds
 * no cross-context class dependency (Deptrac/ArchitectureTest safe). This class is intentionally a
 * REPLICA of Assessment's CourseTenantScope, not a shared import, to keep contexts decoupled.
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
