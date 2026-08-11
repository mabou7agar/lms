<?php

declare(strict_types=1);

namespace App\Platform\AI\Providers\Ollama;

use App\Platform\AI\Data\ChatMessage;
use App\Platform\AI\Data\ChatResult;
use App\Platform\AI\Data\ModelOptions;
use App\Platform\AI\Data\TokenUsage;
use App\Platform\AI\Enums\AiProvider;
use App\Platform\AI\Providers\Http\AbstractHttpChatModel;
use Illuminate\Http\Client\Response;

/**
 * Ollama chat adapter (LOCAL REQUIRED). Ollama runs a local daemon and needs no API key — the
 * "credential" is a reachable base URL. Maps to POST {base}/api/chat with streaming disabled. Makes
 * no network call unless AI_OLLAMA_BASE_URL is configured.
 */
final class OllamaChatModel extends AbstractHttpChatModel
{
    public function provider(): AiProvider
    {
        return AiProvider::Ollama;
    }

    protected function assertConfigured(): void
    {
        $this->requireString($this->config, 'base_url', $this->provider(), 'AI_OLLAMA_BASE_URL');
    }

    protected function endpoint(string $model): string
    {
        return rtrim($this->stringConfig($this->config, 'base_url', 'http://localhost:11434'), '/').'/api/chat';
    }

    protected function headers(): array
    {
        return ['Accept' => 'application/json'];
    }

    protected function payload(array $messages, ModelOptions $options, string $model): array
    {
        return [
            'model' => $model,
            'messages' => array_map(static fn (ChatMessage $m): array => $m->toArray(), $messages),
            'stream' => false,
            'options' => [
                'temperature' => $options->temperature,
                'num_predict' => $options->maxTokens,
            ],
        ];
    }

    protected function parse(Response $response, string $model): ChatResult
    {
        return new ChatResult(
            content: (string) ($response->json('message.content') ?? ''),
            usage: new TokenUsage(
                (int) ($response->json('prompt_eval_count') ?? 0),
                (int) ($response->json('eval_count') ?? 0),
            ),
            provider: $this->provider()->value,
            model: (string) ($response->json('model') ?? $model),
            finishReason: $response->json('done_reason') !== null
                ? (string) $response->json('done_reason')
                : null,
        );
    }
}
