<?php

declare(strict_types=1);

namespace App\Platform\Integration\Exceptions;

use RuntimeException;

/**
 * Raised by WebhookUrlGuard when a destination URL is rejected (non-http(s) scheme, HTTPS required,
 * or a host that resolves to a private/loopback/link-local/metadata address). The short `reason`
 * code is safe to surface to the API caller; the resolved IP is never leaked back.
 */
final class WebhookUrlNotAllowedException extends RuntimeException
{
    public static function reason(string $reason): self
    {
        return new self($reason);
    }
}
