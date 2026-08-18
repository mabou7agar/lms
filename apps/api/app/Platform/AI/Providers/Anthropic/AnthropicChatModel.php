<?php

declare(strict_types=1);

namespace App\Platform\AI\Providers\Anthropic;

use App\Platform\AI\Data\ChatResult;
use App\Platform\AI\Data\ModelOptions;
use App\Platform\AI\Data\TokenUsage;
use App\Platform\AI\Enums\AiProvider;
use App\Platform\AI\Enums\ChatRole;
use App\Platform\AI\Providers\Http\AbstractHttpChatModel;
use Illuminate\Http\Client\Response;

/**
 * Anthropic Messages adapter (LOCAL REQUIRED). Maps to POST {base}/messages. Anthropic separates
 * the system prompt from the message list, so system messages are hoisted into the top-level
 * `system` field. Makes no network call unless ANTHROPIC_API_KEY is configured.
 */
final class AnthropicChatModel extends AbstractHttpChatModel
{
    public function provider(): AiProvider
    {
        return AiProvider::Anthropic;
    }

    protected function assertConfigured(): void
    {
        $this->requireString($this->config, 'api_key', $this->provider(), 'ANTHROPIC_API_KEY');
    }

    protected function endpoint(string $model): string
    {
        return rtrim($this->stringConfig($this->config, 'base_url', 'https://api.anthropic.com/v1'), '/').'/messages';
    }

    protected function headers(): array
    {
        return [
            'x-api-key' => $this->stringConfig($this->config, 'api_key'),
            'anthropic-version' => $this->stringConfig($this->config, 'version', '2023-06-01'),
        ];
    }

    protected function payload(array $messages, ModelOptions $options, string $model): array
    {
        $system = [];
        $turns = [];

        foreach ($messages as $message) {
            if ($message->role === ChatRole::System) {
                $system[] = $message->content;

                continue;
            }

            $turns[] = ['role' => $message->role->value, 'content' => $message->content];
        }

        $payload = [
            'model' => $model,
            'max_tokens' => $options->maxTokens,
            'temperature' => $options->temperature,
            'messages' => $turns,
        ];

        if ($system !== []) {
            $payload['system'] = implode("\n\n", $system);
        }

        return $payload;
    }

    protected function parse(Response $response, string $model): ChatResult
    {
        return new ChatResult(
            content: (string) ($response->json('content.0.text') ?? ''),
            usage: new TokenUsage(
                (int) ($response->json('usage.input_tokens') ?? 0),
                (int) ($response->json('usage.output_tokens') ?? 0),
            ),
            provider: $this->provider()->value,
            model: (string) ($response->json('model') ?? $model),
            finishReason: $response->json('stop_reason') !== null
                ? (string) $response->json('stop_reason')
                : null,
        );
    }
}
