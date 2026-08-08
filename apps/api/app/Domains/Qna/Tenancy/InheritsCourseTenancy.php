<?php

declare(strict_types=1);

namespace App\Domains\Qna\Tenancy;

/**
 * Opt-in seam that makes a course-anchored Q&A model inherit the T1 "global-OR-own-org" tenancy of
 * its owning Course via CourseTenantScope — with NO tenant column read on the model. A denormalised
 * `organization_id` may still be stamped for convenience, but visibility is DERIVED from the course.
 *
 * Deliberately REPLICATED from Assessment's identical seam (never imported) so the Q&A context adds
 * no cross-domain class dependency: it treats `course_id` as an opaque scalar and joins the
 * `courses` table by name only. A model uses this OR the kernel tenant traits — never both. It adds
 * no creating hook: the organization_id stamp is applied explicitly in the Q&A actions, server-side.
 */
trait InheritsCourseTenancy
{
    public static function bootInheritsCourseTenancy(): void
    {
        static::addGlobalScope(new CourseTenantScope);
    }
}
