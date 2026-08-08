<?php

declare(strict_types=1);

namespace App\Domains\Qna\Support;

use App\Platform\Shared\Tenancy\TenantContext;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Turns a course public_id into the internal id (+ owning org) the Q&A context needs, WITHOUT
 * importing Catalog's Course model. It reads the `courses` table by name — the same scalar-FK
 * discipline CourseTenantScope uses — and applies the identical T1 "global-OR-own-org" visibility,
 * so a resolved tenant can never resolve another organization's private course (returns null,
 * indistinguishable from "no such course", so it cannot enumerate courses across tenants).
 *
 * This is a resolution helper only; it is NOT the authorization boundary. Enrollment and
 * instructor-management checks still run in the actions/policies on top of the id it returns.
 */
final class CourseLocator
{
    public function __construct(private readonly TenantContext $tenant) {}

    /** Resolve a tenant-visible course by public_id, or null when absent / not visible. */
    public function locate(string $coursePublicId): ?ResolvedCourse
    {
        $row = $this->query($coursePublicId)->first(['courses.id', 'courses.organization_id']);

        if ($row === null) {
            return null;
        }

        return new ResolvedCourse(
            (int) $row->id,
            $row->organization_id === null ? null : (int) $row->organization_id,
        );
    }

    private function query(string $coursePublicId): QueryBuilder
    {
        $query = DB::table('courses')
            ->where('public_id', $coursePublicId)
            ->whereNull('deleted_at');

        $tenantId = $this->tenant->id()?->value;

        // Mirror CourseTenantScope: when a tenant is resolved, only its own private courses plus the
        // global catalog are visible. No tenant resolved => unfiltered (matches the scope's no-op).
        if ($tenantId !== null) {
            $query->where(function (QueryBuilder $q) use ($tenantId): void {
                $q->whereNull('organization_id')->orWhere('organization_id', $tenantId);
            });
        }

        return $query;
    }
}
