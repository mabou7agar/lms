<?php

namespace App\Domains\Catalog\Exceptions;

/**
 * Raised when an actor tries to manage a course's instructors without the right to. A non-admin
 * (no catalog.courses.manage permission) may only touch instructor assignments on a course they
 * already train — in particular an instructor can NEVER attach themselves to a course they do not
 * own/train. Renders as a 403 via the shared error envelope.
 */
class InstructorAssignmentDeniedException extends CatalogException
{
    protected string $errorCode = 'INSTRUCTOR_ASSIGNMENT_DENIED';

    protected int $status = 403;
}
