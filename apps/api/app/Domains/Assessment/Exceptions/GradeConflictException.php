<?php

namespace App\Domains\Assessment\Exceptions;

use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Thrown when a grader submits a grade against a stale `version` — another grader has written since
 * this one loaded the submission. Surfaces as HTTP 409 so the client can reload and retry rather
 * than silently clobbering the other grader's decision.
 */
class GradeConflictException extends RuntimeException implements HttpExceptionInterface
{
    public function getStatusCode(): int
    {
        return 409;
    }

    /** @return array<string, string> */
    public function getHeaders(): array
    {
        return [];
    }
}
