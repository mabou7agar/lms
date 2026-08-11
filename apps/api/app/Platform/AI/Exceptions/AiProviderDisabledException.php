<?php

declare(strict_types=1);

namespace App\Platform\AI\Exceptions;

/**
 * The requested provider exists in config but is disabled (`enabled => false`). Fails closed.
 */
final class AiProviderDisabledException extends AiException
{
    public function __construct(string $key)
    {
        parent::__construct("AI provider [{$key}] is disabled.");
    }
}
