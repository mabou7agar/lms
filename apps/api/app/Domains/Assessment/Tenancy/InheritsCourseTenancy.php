<?php

declare(strict_types=1);

namespace App\Domains\Assessment\Tenancy;

/**
 * Opt-in seam that makes a course-anchored model (Assessment, Assignment) inherit the T1
 * "global-OR-own-org" tenancy of its owning Course via CourseTenantScope — with NO tenant column on
 * the model or on its children (questions/options/rubrics/criteria/levels inherit transitively,
 * because every read of a child reaches it through its tenant-scoped parent, and every write is gated
 * by the parent's policy).
 *
 * Mirrors the kernel's trait+scope shape (BelongsToTenantNullable + SharedOrOwnedTenantScope) but
 * derives the owner by joining to `courses` instead of reading a local column. A model uses this OR
 * the kernel traits — never both. Unlike BelongsToTenantNullable it adds NO creating hook: an
 * assessment/assignment has no tenant column to stamp; its tenancy is whatever its course's is.
 */
trait InheritsCourseTenancy
{
    public static function bootInheritsCourseTenancy(): void
    {
        static::addGlobalScope(new CourseTenantScope);
    }
}
