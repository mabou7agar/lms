<?php

declare(strict_types=1);

namespace App\Platform\AI\Data;

/**
 * What AiClient::chat() hands back to a feature: the completion plus the audit-grade metadata that
 * accompanies it — the AI-content label, the exact prompt key+version used, the request id that
 * ties it to its ai_usages row, and the metered cost. Features render `content` with `label`.
 */
final class LabeledChatResult
{
    public function __construct(
        public readonly string $content,
        public readonly string $label,
        public readonly TokenUsage $usage,
        public readonly string $provider,
        public readonly string $model,
        public readonly string $requestId,
        public readonly int $estimatedCostMicros,
        public readonly ?string $promptKey = null,
        public readonly ?int $promptVersion = null,
    ) {}
}
