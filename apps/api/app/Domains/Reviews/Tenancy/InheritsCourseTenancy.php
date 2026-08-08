<?php

declare(strict_types=1);

namespace App\Domains\Reviews\Tenancy;

/**
 * Opt-in seam that makes a course-anchored Reviews model (CourseReview) inherit the T1
 * "global-OR-own-org" tenancy of its owning Course via CourseTenantScope — with NO tenant column
 * consulted on the model itself (the review carries a denormalized organization_id for reporting,
 * but visibility is DERIVED transitively from the parent course, never from that column).
 *
 * Replicated from the Assessment context's identically named trait rather than imported: a Domain
 * may not depend on another Domain, and this pattern must join to the `courses` table by string
 * without ever importing Catalog's Course model. A model uses this OR the kernel tenant traits —
 * never both. It adds NO creating hook; a review's tenancy is whatever its course's is.
 */
trait InheritsCourseTenancy
{
    public static function bootInheritsCourseTenancy(): void
    {
        static::addGlobalScope(new CourseTenantScope);
    }
}
