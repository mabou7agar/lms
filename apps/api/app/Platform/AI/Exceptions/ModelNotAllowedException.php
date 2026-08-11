<?php

declare(strict_types=1);

namespace App\Platform\AI\Exceptions;

/**
 * A model was requested that the governance ModelRegistry does not allow for its provider. Fails
 * closed so a caller cannot reach an unvetted/unbudgeted model.
 */
final class ModelNotAllowedException extends AiException
{
    public function __construct(string $provider, string $model)
    {
        parent::__construct("Model [{$model}] is not allowed for AI provider [{$provider}].");
    }
}
