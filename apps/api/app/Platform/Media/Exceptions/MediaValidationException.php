<?php

namespace App\Platform\Media\Exceptions;

use App\Platform\Shared\Exceptions\BaseDomainException;

/**
 * P2/W04 - The requested upload violates its purpose's type/size bounds.
 */
class MediaValidationException extends BaseDomainException
{
    protected string $errorCode = 'MEDIA_VALIDATION';

    protected int $status = 422;
}
