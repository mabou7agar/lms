<?php

declare(strict_types=1);

namespace App\Platform\AI\Exceptions;

/**
 * AI is globally disabled (config('ai.enabled') is false). Fail-closed master switch.
 */
final class AiDisabledException extends AiException
{
    public function __construct(string $message = 'AI is disabled.')
    {
        parent::__construct($message);
    }
}
