<?php

namespace App\Platform\Media\Exceptions;

use App\Platform\Shared\Exceptions\BaseDomainException;

/**
 * P2/W04 - The single-use finalize token was missing, wrong, expired, or already consumed.
 */
class MediaUploadTokenException extends BaseDomainException
{
    protected string $errorCode = 'MEDIA_UPLOAD_TOKEN_INVALID';

    protected int $status = 422;
}
