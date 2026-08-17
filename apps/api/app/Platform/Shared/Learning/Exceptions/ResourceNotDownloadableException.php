<?php

namespace App\Platform\Shared\Learning\Exceptions;

use App\Platform\Shared\Exceptions\BaseDomainException;

/**
 * The file exists and the caller is entitled to the course, but this resource is view-only.
 *
 * Kept apart from the access refusals because it says nothing about the caller: buying more, or
 * renewing, changes nothing. The client should stop offering a download, not offer a remedy.
 */
class ResourceNotDownloadableException extends BaseDomainException
{
    protected string $errorCode = 'RESOURCE_NOT_DOWNLOADABLE';

    protected int $status = 403;

    public function __construct(string $message = 'This file is not available for download.', array $details = [])
    {
        parent::__construct($message, $details);
    }
}
