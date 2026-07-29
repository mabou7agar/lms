<?php

namespace App\Domains\Authoring\Exceptions;

class VersionConflictException extends AuthoringException
{
    protected string $errorCode = 'AUTHORING_VERSION_CONFLICT';

    protected int $status = 409;

    /** @param array<string, mixed> $details */
    public function __construct(string $message = 'A concurrent version was created for this course. Please retry.', array $details = [])
    {
        parent::__construct($message, $details);
    }
}
