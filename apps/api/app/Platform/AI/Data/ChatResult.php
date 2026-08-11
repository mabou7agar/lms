<?php

declare(strict_types=1);

namespace App\Platform\AI\Data;

/**
 * The provider-neutral result of a single chat completion: the assistant text plus the token
 * accounting metering/quotas need. `provider`/`model` are echoed back so a caller records exactly
 * what served the request.
 */
final class ChatResult
{
    public function __construct(
        public readonly string $content,
        public readonly TokenUsage $usage,
        public readonly string $provider,
        public readonly string $model,
        public readonly ?string $finishReason = null,
    ) {}
}
