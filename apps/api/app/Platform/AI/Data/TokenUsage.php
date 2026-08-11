<?php

declare(strict_types=1);

namespace App\Platform\AI\Data;

/**
 * Immutable token accounting for a single AI call. Provider-neutral: every adapter maps its own
 * usage payload onto this shape, so metering and quotas never learn a vendor's field names.
 */
final class TokenUsage
{
    public function __construct(
        public readonly int $inputTokens = 0,
        public readonly int $outputTokens = 0,
    ) {}

    public static function zero(): self
    {
        return new self(0, 0);
    }

    public function total(): int
    {
        return $this->inputTokens + $this->outputTokens;
    }

    public function plus(self $other): self
    {
        return new self(
            $this->inputTokens + $other->inputTokens,
            $this->outputTokens + $other->outputTokens,
        );
    }
}
