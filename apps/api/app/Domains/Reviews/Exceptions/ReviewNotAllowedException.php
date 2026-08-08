<?php

namespace App\Domains\Reviews\Exceptions;

use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Thrown when the caller is not permitted to review a course — either they are not enrolled/entitled
 * or they are the course's own instructor. Surfaces as HTTP 403 (Forbidden).
 */
class ReviewNotAllowedException extends RuntimeException implements HttpExceptionInterface
{
    public function __construct(string $message = 'You are not allowed to review this course.')
    {
        parent::__construct($message);
    }

    public function getStatusCode(): int
    {
        return 403;
    }

    /** @return array<string, string> */
    public function getHeaders(): array
    {
        return [];
    }
}
