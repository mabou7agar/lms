<?php

declare(strict_types=1);

namespace App\Platform\AI;

use App\Platform\AI\Data\EmbeddingResult;
use App\Platform\AI\Data\LabeledChatResult;
use App\Platform\AI\Data\ModelOptions;
use App\Platform\AI\Data\UsageContext;
use App\Platform\AI\Enums\AiFeature;
use App\Platform\AI\Enums\AiProvider;
use App\Platform\AI\Governance\AiGovernance;
use App\Platform\AI\Governance\ContentLabeler;
use App\Platform\AI\Governance\ModelRegistry;
use App\Platform\AI\Governance\PromptInjectionGuard;
use App\Platform\AI\Metering\AiQuotaGuard;
use App\Platform\AI\Metering\AiUsageRecorder;
use App\Platform\AI\Prompts\PromptLibrary;
use App\Platform\AI\Support\TokenEstimator;
use App\Platform\Shared\Helpers\Uuid;
use App\Platform\Shared\Tenancy\TenantContext;

/**
 * The one entry point later AI features (tutor, copilot, analytics assistant, semantic search) call.
 * It composes the whole foundation into a single guarded pipeline:
 *
 *   governance gate → resolve prompt version → sanitize untrusted vars → pick provider/model →
 *   model-registry allow-list → pre-call quota gate → provider call → record usage (tokens + cost +
 *   prompt key/version) → return an AI-labelled result.
 *
 * Everything runs through the FAKE provider by default, so features + tests work without any
 * credentials. Nothing here reads a secret; provider config is injected inside the manager.
 */
final class AiClient
{
    public function __construct(
        private readonly AiProviderManager $manager,
        private readonly AiGovernance $governance,
        private readonly AiQuotaGuard $quota,
        private readonly AiUsageRecorder $recorder,
        private readonly PromptLibrary $prompts,
        private readonly ModelRegistry $registry,
        private readonly PromptInjectionGuard $injection,
        private readonly ContentLabeler $labeler,
        private readonly TokenEstimator $estimator,
        private readonly TenantContext $tenant,
    ) {}

    /**
     * Run a chat completion from a library prompt.
     *
     * @param  array<string, mixed>  $variables  untrusted values interpolated into the prompt
     */
    public function chat(
        AiFeature $feature,
        string $promptKey,
        array $variables = [],
        ?int $userId = null,
        ?int $courseId = null,
        ?string $locale = null,
        ?string $provider = null,
        ?ModelOptions $options = null,
    ): LabeledChatResult {
        $organizationId = $this->currentOrganizationId();

        $this->governance->assertEnabled($feature, $organizationId, $courseId);

        $locale ??= (string) app()->getLocale();
        $safeVariables = $this->injection->sanitizeVariables($variables);

        $rendered = $this->prompts->render($promptKey, $safeVariables, $locale);

        [$preferredProvider, $preferredModel] = $this->parsePreference($rendered->modelPreference);
        $providerEnum = $this->manager->resolve($provider ?? $preferredProvider);
        $model = $preferredModel ?? $this->defaultChatModel($providerEnum);

        $this->registry->assertAllowed($providerEnum, $model);

        $options = $this->resolveOptions($options)->withModel($model);
        $messages = $rendered->toMessages();

        $estimatedTokens = $this->estimator->estimateMessages($messages) + $options->maxTokens;
        $this->quota->assertWithinLimits($estimatedTokens, $organizationId, $userId);

        $result = $this->manager->chatModel($providerEnum->value)->chat($messages, $options);

        $requestId = Uuid::v7();
        $usage = $this->recorder->record(
            new UsageContext(
                feature: $feature,
                provider: $providerEnum,
                model: $result->model,
                organizationId: $organizationId,
                userId: $userId,
                requestId: $requestId,
                promptKey: $rendered->key,
                promptVersion: $rendered->version,
            ),
            $result->usage,
        );

        return new LabeledChatResult(
            content: $result->content,
            label: $this->labeler->label(),
            usage: $result->usage,
            provider: $result->provider,
            model: $result->model,
            requestId: $requestId,
            estimatedCostMicros: $usage->estimated_cost_micros,
            promptKey: $rendered->key,
            promptVersion: $rendered->version,
        );
    }

    /**
     * Embed one or more texts, metered like any other AI call.
     *
     * @param  list<string>  $texts
     */
    public function embed(
        array $texts,
        AiFeature $feature = AiFeature::Embedding,
        ?int $userId = null,
        ?string $provider = null,
        ?ModelOptions $options = null,
    ): EmbeddingResult {
        $organizationId = $this->currentOrganizationId();

        $this->governance->assertEnabled($feature, $organizationId);

        $providerEnum = $this->manager->resolve($provider);
        $model = $this->defaultEmbeddingModel($providerEnum);
        $this->registry->assertAllowed($providerEnum, $model);

        $options = $this->resolveOptions($options)->withModel($model);

        $estimatedTokens = 0;
        foreach ($texts as $text) {
            $estimatedTokens += $this->estimator->estimate($text);
        }
        $this->quota->assertWithinLimits($estimatedTokens, $organizationId, $userId);

        $result = $this->manager->embeddingModel($providerEnum->value)->embed($texts, $options);

        $this->recorder->record(
            new UsageContext(
                feature: $feature,
                provider: $providerEnum,
                model: $result->model,
                organizationId: $organizationId,
                userId: $userId,
                requestId: Uuid::v7(),
            ),
            $result->usage,
        );

        return $result;
    }

    private function resolveOptions(?ModelOptions $options): ModelOptions
    {
        /** @var array<string, mixed> $defaults */
        $defaults = (array) config('ai.defaults', []);
        $options ??= ModelOptions::fromDefaults($defaults);

        $maxOutput = (int) config('ai.limits.max_output_tokens', 0);
        if ($maxOutput > 0 && $options->maxTokens > $maxOutput) {
            $options = $options->withMaxTokens($maxOutput);
        }

        return $options;
    }

    private function defaultChatModel(AiProvider $provider): string
    {
        $model = config('ai.providers.'.$provider->value.'.chat_model');

        return is_string($model) && $model !== '' ? $model : 'default';
    }

    private function defaultEmbeddingModel(AiProvider $provider): string
    {
        $model = config('ai.providers.'.$provider->value.'.embedding_model');

        return is_string($model) && $model !== '' ? $model : 'default';
    }

    /**
     * Parse a prompt's model_preference: "provider:model" | "model" | null.
     *
     * @return array{0: string|null, 1: string|null}
     */
    private function parsePreference(?string $preference): array
    {
        if ($preference === null || trim($preference) === '') {
            return [null, null];
        }

        if (str_contains($preference, ':')) {
            [$provider, $model] = explode(':', $preference, 2);

            return [trim($provider) !== '' ? trim($provider) : null, trim($model) !== '' ? trim($model) : null];
        }

        return [null, trim($preference)];
    }

    /** The active tenant as an integer organization id, or null when unscoped/non-numeric. */
    private function currentOrganizationId(): ?int
    {
        $tenantId = $this->tenant->id();

        if ($tenantId === null) {
            return null;
        }

        return is_numeric($tenantId->value) ? (int) $tenantId->value : null;
    }
}
