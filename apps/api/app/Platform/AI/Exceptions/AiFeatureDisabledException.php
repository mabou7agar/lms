<?php

declare(strict_types=1);

namespace App\Platform\AI\Exceptions;

/**
 * Governance blocked the call: AI is disabled for this tenant, this feature, or this course. Fails
 * closed. `reason` names the governance scope (tenant | feature | course).
 */
final class AiFeatureDisabledException extends AiException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
