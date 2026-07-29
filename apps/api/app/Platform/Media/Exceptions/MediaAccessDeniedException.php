<?php

namespace App\Platform\Media\Exceptions;

use App\Platform\Shared\Exceptions\BaseDomainException;

/**
 * P2/W04 - The actor may not use/see this asset. Rendered as 404 so it never reveals whether the
 * asset exists (no existence leak across ownership/tenant boundaries).
 */
class MediaAccessDeniedException extends BaseDomainException
{
    protected string $errorCode = 'MEDIA_NOT_FOUND';

    protected int $status = 404;

    /** @param array<string, mixed> $details */
    public function __construct(string $message = 'Media not found.', array $details = [])
    {
        parent::__construct($message, $details);
    }
}
