<?php

declare(strict_types=1);

namespace App\Platform\AI\Providers\OpenAi;

use App\Platform\AI\Data\ChatMessage;
use App\Platform\AI\Data\ChatResult;
use App\Platform\AI\Data\ModelOptions;
use App\Platform\AI\Data\TokenUsage;
use App\Platform\AI\Enums\AiProvider;
use App\Platform\AI\Providers\Http\AbstractHttpChatModel;
use Illuminate\Http\Client\Response;

/**
 * OpenAI Chat Completions adapter (LOCAL REQUIRED). Maps to POST {base}/chat/completions. Makes no
 * network call unless OPENAI_API_KEY is configured.
 */
class OpenAiChatModel extends AbstractHttpChatModel
{
    public function provider(): AiProvider
    {
        return AiProvider::OpenAi;
    }

    protected function assertConfigured(): void
    {
        $this->requireString($this->config, 'api_key', $this->provider(), 'OPENAI_API_KEY');
    }

    protected function endpoint(string $model): string
    {
        return rtrim($this->stringConfig($this->config, 'base_url', 'https://api.openai.com/v1'), '/').'/chat/completions';
    }

    protected function headers(): array
    {
        $headers = ['Authorization' => 'Bearer '.$this->stringConfig($this->config, 'api_key')];

        $org = $this->stringConfig($this->config, 'organization');
        if ($org !== '') {
            $headers['OpenAI-Organization'] = $org;
        }

        return $headers;
    }

    protected function payload(array $messages, ModelOptions $options, string $model): array
    {
        return [
            'model' => $model,
            'messages' => array_map(static fn (ChatMessage $m): array => $m->toArray(), $messages),
            'temperature' => $options->temperature,
            'max_tokens' => $options->maxTokens,
        ];
    }

    protected function parse(Response $response, string $model): ChatResult
    {
        return new ChatResult(
            content: (string) ($response->json('choices.0.message.content') ?? ''),
            usage: new TokenUsage(
                (int) ($response->json('usage.prompt_tokens') ?? 0),
                (int) ($response->json('usage.completion_tokens') ?? 0),
            ),
            provider: $this->provider()->value,
            model: (string) ($response->json('model') ?? $model),
            finishReason: $response->json('choices.0.finish_reason') !== null
                ? (string) $response->json('choices.0.finish_reason')
                : null,
        );
    }
}
