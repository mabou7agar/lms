<?php

declare(strict_types=1);

namespace App\Platform\AI\Exceptions;

/**
 * No active prompt version exists for the requested key/locale. Fails closed — a feature never
 * falls back to an ad-hoc prompt string.
 */
final class PromptNotFoundException extends AiException
{
    public function __construct(string $key, string $locale)
    {
        parent::__construct("No active AI prompt found for key [{$key}] locale [{$locale}].");
    }
}
