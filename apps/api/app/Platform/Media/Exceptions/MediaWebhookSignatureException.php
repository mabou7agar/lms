<?php

namespace App\Platform\Media\Exceptions;

use App\Platform\Shared\Exceptions\BaseDomainException;

/**
 * P2/W04 - A provider webhook failed signature verification and was rejected before any processing.
 */
class MediaWebhookSignatureException extends BaseDomainException
{
    protected string $errorCode = 'MEDIA_WEBHOOK_SIGNATURE';

    protected int $status = 400;
}
