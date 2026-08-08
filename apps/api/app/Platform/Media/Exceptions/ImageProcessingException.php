<?php

namespace App\Platform\Media\Exceptions;

use App\Platform\Shared\Exceptions\BaseDomainException;

/**
 * Phase A / D6 - A TRANSIENT / internal failure of the image pipeline: the GD codec failed to decode
 * or encode, the original object was missing from storage, or an encoder the runtime should support
 * was unavailable at run time. Unlike ImageRejectedException this is NOT necessarily a property of the
 * input, so the queued job lets it propagate and be retried with backoff before dead-lettering.
 */
class ImageProcessingException extends BaseDomainException
{
    protected string $errorCode = 'MEDIA_IMAGE_PROCESSING_FAILED';

    protected int $status = 500;
}
