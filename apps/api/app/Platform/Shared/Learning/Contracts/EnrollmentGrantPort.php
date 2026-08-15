<?php

namespace App\Platform\Shared\Learning\Contracts;

/**
 * Grant a learner an entitlement (enrollment) to a course from a NON-Learning context — e.g. the CRM
 * enterprise portal assigning a course to org members — without importing Learning's Enrollment model
 * or GrantEnrollmentAction. DECLARED here in Shared, IMPLEMENTED by the Learning context, so callers
 * never cross a layer boundary. Idempotent: re-granting an existing entitlement is a no-op.
 */
interface EnrollmentGrantPort
{
    /** Idempotently grant the user access to the course (administrative / enterprise grant). */
    public function grant(int $courseId, int $userId): void;
}
