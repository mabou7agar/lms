<?php

namespace App\Platform\Media\Exceptions;

use App\Platform\Shared\Exceptions\BaseDomainException;

/**
 * Phase A / D6 - A PERMANENT rejection of an image by the pipeline: the bytes are not a supported
 * image (magic-byte check), exceed the configured size/dimension/pixel budget, or are otherwise a
 * decompression-bomb risk. This is deterministic for a given input, so the queued job treats it as
 * terminal (it audits the rejection and does NOT retry) rather than a transient processing failure.
 */
class ImageRejectedException extends BaseDomainException
{
    protected string $errorCode = 'MEDIA_IMAGE_REJECTED';

    protected int $status = 422;
}
