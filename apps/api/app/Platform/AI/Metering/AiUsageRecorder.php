<?php

declare(strict_types=1);

namespace App\Platform\AI\Metering;

use App\Platform\AI\Data\TokenUsage;
use App\Platform\AI\Data\UsageContext;
use App\Platform\AI\Models\AiUsage;
use App\Platform\Shared\Audit\AuditLogger;

/**
 * The single write-path every AI call routes through after the provider responds. Persists one
 * immutable ai_usages row (tokens + estimated cost + the exact prompt key/version) and writes a
 * parallel audit entry, so cost, quota accounting, and governance provenance all derive from one
 * recorded fact. Secrets are never recorded — only provider/model identifiers.
 */
final class AiUsageRecorder
{
    public function __construct(
        private readonly CostCalculator $costCalculator,
        private readonly AuditLogger $audit,
    ) {}

    public function record(UsageContext $context, TokenUsage $usage): AiUsage
    {
        $costMicros = $this->costCalculator->micros($context->provider, $context->model, $usage);

        /** @var AiUsage $row */
        $row = AiUsage::create([
            'organization_id' => $context->organizationId,
            'user_id' => $context->userId,
            'feature' => $context->feature->value,
            'provider' => $context->provider->value,
            'model' => $context->model,
            'input_tokens' => $usage->inputTokens,
            'output_tokens' => $usage->outputTokens,
            'estimated_cost_micros' => $costMicros,
            'request_id' => $context->requestId,
            'prompt_key' => $context->promptKey,
            'prompt_version' => $context->promptVersion,
            'created_at' => now(),
        ]);

        // Audit hook: every AI run records provider, model, prompt key+version, tokens, cost, owner.
        $this->audit->log('ai.run', $row, [
            'feature' => $context->feature->value,
            'provider' => $context->provider->value,
            'model' => $context->model,
            'prompt_key' => $context->promptKey,
            'prompt_version' => $context->promptVersion,
            'input_tokens' => $usage->inputTokens,
            'output_tokens' => $usage->outputTokens,
            'estimated_cost_micros' => $costMicros,
            'request_id' => $context->requestId,
            'organization_id' => $context->organizationId,
        ], $context->userId);

        return $row;
    }
}
