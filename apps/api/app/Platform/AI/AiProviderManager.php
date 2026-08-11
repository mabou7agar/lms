<?php

declare(strict_types=1);

namespace App\Platform\AI;

use App\Platform\AI\Contracts\ChatModel;
use App\Platform\AI\Contracts\EmbeddingModel;
use App\Platform\AI\Enums\AiProvider;
use App\Platform\AI\Exceptions\AiDisabledException;
use App\Platform\AI\Exceptions\AiProviderDisabledException;
use App\Platform\AI\Exceptions\EmbeddingsUnsupportedException;
use App\Platform\AI\Exceptions\FakeProviderNotAllowedException;
use App\Platform\AI\Exceptions\UnknownAiProviderException;
use App\Platform\AI\Providers\Anthropic\AnthropicChatModel;
use App\Platform\AI\Providers\Fake\FakeChatModel;
use App\Platform\AI\Providers\Fake\FakeEmbeddingModel;
use App\Platform\AI\Providers\Gemini\GeminiChatModel;
use App\Platform\AI\Providers\Gemini\GeminiEmbeddingModel;
use App\Platform\AI\Providers\Ollama\OllamaChatModel;
use App\Platform\AI\Providers\Ollama\OllamaEmbeddingModel;
use App\Platform\AI\Providers\OpenAi\OpenAiChatModel;
use App\Platform\AI\Providers\OpenAi\OpenAiEmbeddingModel;
use App\Platform\AI\Providers\OpenRouter\OpenRouterChatModel;
use App\Platform\AI\Support\TokenEstimator;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory;

/**
 * Resolves a ChatModel / EmbeddingModel for a provider from config('ai.*'), mirroring the Commerce
 * gateway / SocialAuthManager "real-by-config, fail-closed, fake-in-tests" contract:
 *   - AI off entirely, an unknown key, or a disabled provider each fail closed with a clear error;
 *   - the `fake` provider is refused in production unless AI_ALLOW_FAKE is set, so a misconfigured
 *     deploy can never silently serve stub AI output as if a real model answered;
 *   - vendor config is injected into each adapter here, so no other code reads AI secrets.
 *
 * Real adapters are LOCAL REQUIRED: resolving one never touches the network — it only calls out when
 * its credentials are configured, and throws ProviderCredentialsRequiredException otherwise.
 */
final class AiProviderManager
{
    public function __construct(
        private readonly Application $app,
        private readonly Factory $http,
    ) {}

    public function chatModel(?string $provider = null): ChatModel
    {
        $resolved = $this->resolve($provider);
        $config = $this->providerConfig($resolved);

        return match ($resolved) {
            AiProvider::Fake => new FakeChatModel(
                $this->app->make(TokenEstimator::class),
                $this->stringOr($config, 'chat_model', 'fake-chat-v1'),
            ),
            AiProvider::OpenAi => new OpenAiChatModel($this->http, $config),
            AiProvider::Anthropic => new AnthropicChatModel($this->http, $config),
            AiProvider::Gemini => new GeminiChatModel($this->http, $config),
            AiProvider::OpenRouter => new OpenRouterChatModel($this->http, $config),
            AiProvider::Ollama => new OllamaChatModel($this->http, $config),
        };
    }

    public function embeddingModel(?string $provider = null): EmbeddingModel
    {
        $resolved = $this->resolve($provider);
        $config = $this->providerConfig($resolved);

        return match ($resolved) {
            AiProvider::Fake => new FakeEmbeddingModel(
                $this->app->make(TokenEstimator::class),
                $this->stringOr($config, 'embedding_model', 'fake-embed-v1'),
                (int) ($config['embedding_dimensions'] ?? 128),
            ),
            AiProvider::OpenAi => new OpenAiEmbeddingModel($this->http, $config),
            AiProvider::Gemini => new GeminiEmbeddingModel($this->http, $this->app->make(TokenEstimator::class), $config),
            AiProvider::Ollama => new OllamaEmbeddingModel($this->http, $this->app->make(TokenEstimator::class), $config),
            AiProvider::Anthropic, AiProvider::OpenRouter => throw new EmbeddingsUnsupportedException($resolved->value),
        };
    }

    /** Fail-closed provider resolution shared by chat + embeddings. */
    public function resolve(?string $provider = null): AiProvider
    {
        if ((bool) config('ai.enabled', false) !== true) {
            throw new AiDisabledException;
        }

        $key = $provider ?? (string) config('ai.default_provider', 'fake');

        $config = config('ai.providers.'.$key);
        if (! is_array($config)) {
            throw new UnknownAiProviderException($key);
        }

        $enum = AiProvider::tryFrom($key);
        if ($enum === null) {
            throw new UnknownAiProviderException($key);
        }

        if ((bool) ($config['enabled'] ?? false) !== true) {
            throw new AiProviderDisabledException($key);
        }

        if ($enum === AiProvider::Fake
            && $this->app->environment('production')
            && (bool) config('ai.allow_fake', false) !== true) {
            throw new FakeProviderNotAllowedException;
        }

        return $enum;
    }

    /** @return array<string, mixed> */
    private function providerConfig(AiProvider $provider): array
    {
        /** @var array<string, mixed> $config */
        $config = (array) config('ai.providers.'.$provider->value, []);

        return $config;
    }

    /** @param array<string, mixed> $config */
    private function stringOr(array $config, string $key, string $default): string
    {
        $value = $config[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : $default;
    }
}
