<?php

namespace App\Domains\Authoring\Exceptions;

class UnsupportedSnapshotSchemaException extends AuthoringException
{
    protected string $errorCode = 'AUTHORING_UNSUPPORTED_SNAPSHOT_SCHEMA';

    protected int $status = 422;

    /** @param array<string, mixed> $details */
    public function __construct(string $message = 'Unsupported snapshot schema version.', array $details = [])
    {
        parent::__construct($message, $details);
    }
}
