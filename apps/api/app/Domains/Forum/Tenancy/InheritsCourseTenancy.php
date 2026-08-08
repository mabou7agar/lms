<?php

declare(strict_types=1);

namespace App\Domains\Forum\Tenancy;

/**
 * Opt-in seam that makes a course-anchored Forum model (ForumThread) inherit the T1
 * "global-OR-own-org" tenancy of its owning Course via CourseTenantScope — with NO reliance on a
 * local tenant column. A ForumPost has no `course_id` of its own; its tenancy is inherited
 * transitively because every read of a post reaches it through its already-scoped parent thread.
 *
 * Replicated (not imported) from the Assessment context's identical seam: this domain treats
 * `course_id` as an opaque scalar and joins the `courses` TABLE by name only, so it never couples to
 * Catalog's Course model (Deptrac-safe).
 */
trait InheritsCourseTenancy
{
    public static function bootInheritsCourseTenancy(): void
    {
        static::addGlobalScope(new CourseTenantScope);
    }
}
