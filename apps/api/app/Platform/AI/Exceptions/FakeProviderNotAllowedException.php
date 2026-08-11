<?php

declare(strict_types=1);

namespace App\Platform\AI\Exceptions;

/**
 * The deterministic fake provider was selected in production without AI_ALLOW_FAKE. Refused so a
 * misconfigured deploy can never silently serve stub AI output as if a real model answered.
 */
final class FakeProviderNotAllowedException extends AiException
{
    public function __construct(string $message = 'The fake AI provider is not permitted in production (set AI_ALLOW_FAKE=true only for a deliberate non-AI environment).')
    {
        parent::__construct($message);
    }
}
