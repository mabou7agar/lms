<?php

namespace App\Platform\Shared\Learning\Exceptions;

use App\Platform\Shared\Exceptions\BaseDomainException;

/**
 * The caller holds no entitlement to this course.
 *
 * Declared in Shared because every context that gates on CourseEnrollmentPort — Q&A, course files,
 * assessments — needs to refuse the same way, and none of them may reach into Learning for its own
 * exception classes. It is the counterpart of the port: whoever asks the port may throw this.
 */
class CourseAccessDeniedException extends BaseDomainException
{
    protected string $errorCode = 'COURSE_ACCESS_DENIED';

    protected int $status = 403;

    public function __construct(string $message = 'You do not have access to this course.', array $details = [])
    {
        parent::__construct($message, $details);
    }
}
