<?php

namespace App\Domains\Assessment\Exceptions;

use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Thrown when a submit/resubmit is refused for a business reason (not published, not enrolled, past
 * a blocking due date, attempt limit reached, wrong submission shape). HTTP 422.
 */
class SubmissionNotAllowedException extends RuntimeException implements HttpExceptionInterface
{
    public function getStatusCode(): int
    {
        return 422;
    }

    /** @return array<string, string> */
    public function getHeaders(): array
    {
        return [];
    }
}
