<?php

declare(strict_types=1);

namespace App\Platform\AI\Providers\Gemini;

use App\Platform\AI\Data\ChatResult;
use App\Platform\AI\Data\ModelOptions;
use App\Platform\AI\Data\TokenUsage;
use App\Platform\AI\Enums\AiProvider;
use App\Platform\AI\Enums\ChatRole;
use App\Platform\AI\Providers\Http\AbstractHttpChatModel;
use Illuminate\Http\Client\Response;

/**
 * Google Gemini generateContent adapter (LOCAL REQUIRED). Maps to
 * POST {base}/models/{model}:generateContent?key=API_KEY. Gemini uses `contents` with `role`+`parts`
 * and a separate `systemInstruction`. Makes no network call unless GEMINI_API_KEY is configured.
 */
final class GeminiChatModel extends AbstractHttpChatModel
{
    public function provider(): AiProvider
    {
        return AiProvider::Gemini;
    }

    protected function assertConfigured(): void
    {
        $this->requireString($this->config, 'api_key', $this->provider(), 'GEMINI_API_KEY');
    }

    protected function endpoint(string $model): string
    {
        $base = rtrim($this->stringConfig($this->config, 'base_url', 'https://generativelanguage.googleapis.com/v1beta'), '/');

        return $base.'/models/'.$model.':generateContent?key='.$this->stringConfig($this->config, 'api_key');
    }

    protected function headers(): array
    {
        return ['Content-Type' => 'application/json'];
    }

    protected function payload(array $messages, ModelOptions $options, string $model): array
    {
        $contents = [];
        $system = [];

        foreach ($messages as $message) {
            if ($message->role === ChatRole::System) {
                $system[] = $message->content;

                continue;
            }

            $contents[] = [
                // Gemini uses 'model' for the assistant role.
                'role' => $message->role === ChatRole::Assistant ? 'model' : 'user',
                'parts' => [['text' => $message->content]],
            ];
        }

        $payload = [
            'contents' => $contents,
            'generationConfig' => [
                'temperature' => $options->temperature,
                'maxOutputTokens' => $options->maxTokens,
            ],
        ];

        if ($system !== []) {
            $payload['systemInstruction'] = ['parts' => [['text' => implode("\n\n", $system)]]];
        }

        return $payload;
    }

    protected function parse(Response $response, string $model): ChatResult
    {
        return new ChatResult(
            content: (string) ($response->json('candidates.0.content.parts.0.text') ?? ''),
            usage: new TokenUsage(
                (int) ($response->json('usageMetadata.promptTokenCount') ?? 0),
                (int) ($response->json('usageMetadata.candidatesTokenCount') ?? 0),
            ),
            provider: $this->provider()->value,
            model: $model,
            finishReason: $response->json('candidates.0.finishReason') !== null
                ? (string) $response->json('candidates.0.finishReason')
                : null,
        );
    }
}
