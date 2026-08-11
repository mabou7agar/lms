<?php

declare(strict_types=1);

namespace App\Platform\AI\Exceptions;

/**
 * A provider key was requested that has no config('ai.providers.*') block. Fails closed.
 */
final class UnknownAiProviderException extends AiException
{
    public function __construct(string $key)
    {
        parent::__construct("Unknown AI provider [{$key}].");
    }
}
