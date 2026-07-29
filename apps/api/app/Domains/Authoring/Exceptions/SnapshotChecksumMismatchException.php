<?php

namespace App\Domains\Authoring\Exceptions;

class SnapshotChecksumMismatchException extends AuthoringException
{
    protected string $errorCode = 'AUTHORING_SNAPSHOT_CHECKSUM_MISMATCH';

    protected int $status = 409;

    /** @param array<string, mixed> $details */
    public function __construct(string $message = 'The stored snapshot failed its integrity check.', array $details = [])
    {
        parent::__construct($message, $details);
    }
}
