<?php

namespace App\Platform\Shared\Learning\Support;

use App\Platform\Shared\Learning\Contracts\CourseEnrollmentPort;
use App\Platform\Shared\Learning\Exceptions\CourseAccessDeniedException;
use App\Platform\Shared\Learning\Exceptions\CourseAccessExpiredException;

/**
 * "Does this learner still hold the course, and if not, why not?"
 *
 * Q&A, course files and assessments each asked the port and then threw a bare HTTP 403, which
 * rendered outside the standard envelope with no code on it. Rather than repeat the same two-line
 * decision in each of them, they call this and get the refusal that fits: expired for someone whose
 * window closed, denied for someone who never had one.
 *
 * Deliberately a tiny stateless helper over the port, not a service: it holds no state, decides
 * nothing a context owns, and adding it to a context would put every other context's gate in it.
 */
final class CourseAccessGuard
{
    public function __construct(private readonly CourseEnrollmentPort $enrollments) {}

    /** Throws CourseAccessExpiredException or CourseAccessDeniedException when access is refused. */
    public function assert(int $courseId, int $userId): void
    {
        if ($this->enrollments->hasCourseAccess($courseId, $userId)) {
            return;
        }

        throw $this->enrollments->accessWindowElapsed($courseId, $userId)
            ? new CourseAccessExpiredException
            : new CourseAccessDeniedException;
    }
}
