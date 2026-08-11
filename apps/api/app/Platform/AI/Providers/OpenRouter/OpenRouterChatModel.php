<?php

declare(strict_types=1);

namespace App\Platform\AI\Providers\OpenRouter;

use App\Platform\AI\Enums\AiProvider;
use App\Platform\AI\Providers\OpenAi\OpenAiChatModel;

/**
 * OpenRouter chat adapter (LOCAL REQUIRED). OpenRouter is OpenAI-wire-compatible, so this reuses the
 * OpenAI Chat Completions shape and only overrides the provider identity, default base URL, and
 * credential env var. Makes no network call unless OPENROUTER_API_KEY is configured.
 */
final class OpenRouterChatModel extends OpenAiChatModel
{
    public function provider(): AiProvider
    {
        return AiProvider::OpenRouter;
    }

    protected function assertConfigured(): void
    {
        $this->requireString($this->config, 'api_key', $this->provider(), 'OPENROUTER_API_KEY');
    }

    protected function endpoint(string $model): string
    {
        return rtrim($this->stringConfig($this->config, 'base_url', 'https://openrouter.ai/api/v1'), '/').'/chat/completions';
    }
}
