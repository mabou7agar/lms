<?php

namespace App\Domains\Reviews\Exceptions;

use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Thrown when a learner tries to post a second active review for a course they have already
 * reviewed. Surfaces as HTTP 409 (Conflict) — the client should edit the existing review instead.
 */
class DuplicateReviewException extends RuntimeException implements HttpExceptionInterface
{
    public function __construct(string $message = 'You have already reviewed this course.')
    {
        parent::__construct($message);
    }

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
