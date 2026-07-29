<?php

namespace App\Platform\Media\Exceptions;

use App\Platform\Shared\Exceptions\BaseDomainException;

/**
 * P2/W04 - An operation required a playable (ready) asset but the asset is not ready — e.g.
 * attaching it to content or accepting it as a submission file before processing completes.
 */
class MediaNotReadyException extends BaseDomainException
{
    protected string $errorCode = 'MEDIA_NOT_READY';

    protected int $status = 409;
}
