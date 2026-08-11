<?php

declare(strict_types=1);

namespace App\Platform\Integration\Enums;

/**
 * Lifecycle of a single outbound webhook delivery.
 *
 * pending  — created or awaiting a (re)try.
 * success  — the endpoint returned a 2xx response.
 * failed   — permanently failed (retries exhausted or a non-retryable error).
 */
enum DeliveryStatus: string
{
    case Pending = 'pending';
    case Success = 'success';
    case Failed = 'failed';

    public function isTerminal(): bool
    {
        return $this === self::Success || $this === self::Failed;
    }
}
