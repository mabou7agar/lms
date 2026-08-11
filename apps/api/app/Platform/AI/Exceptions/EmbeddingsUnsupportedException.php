<?php

declare(strict_types=1);

namespace App\Platform\AI\Exceptions;

/**
 * The selected provider has no first-class embedding endpoint in this foundation (e.g. Anthropic /
 * OpenRouter). Fails closed rather than silently routing embeddings to a chat model.
 */
final class EmbeddingsUnsupportedException extends AiException
{
    public function __construct(string $provider)
    {
        parent::__construct("AI provider [{$provider}] does not support embeddings.");
    }
}
