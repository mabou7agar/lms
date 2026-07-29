<?php

namespace App\Platform\Media\Exceptions;

use App\Platform\Shared\Exceptions\BaseDomainException;

/**
 * P2/W04 - A lifecycle transition was refused by MediaStatus (e.g. retrying a non-failed asset).
 * Webhook-driven backward/out-of-order transitions are swallowed silently instead of throwing this
 * (see MediaIngestionService::processWebhook); this is for caller-initiated operations.
 */
class MediaTransitionException extends BaseDomainException
{
    protected string $errorCode = 'MEDIA_INVALID_TRANSITION';

    protected int $status = 409;
}
