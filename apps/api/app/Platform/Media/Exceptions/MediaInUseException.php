<?php

namespace App\Platform\Media\Exceptions;

use App\Platform\Shared\Exceptions\BaseDomainException;

/**
 * P2/W04 - Refused to delete an asset that is still attached somewhere. The caller may force a
 * cascading detach explicitly.
 */
class MediaInUseException extends BaseDomainException
{
    protected string $errorCode = 'MEDIA_IN_USE';

    protected int $status = 409;
}
