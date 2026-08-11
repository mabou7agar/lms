<?php

declare(strict_types=1);

namespace App\Platform\AI\Enums;

/**
 * The provider-neutral set of AI backends the platform can be wired to.
 *
 * `Fake` is the deterministic, network-free default used everywhere in local/test — every AI
 * feature and every test runs through it without credentials. The remaining cases map to real
 * vendor HTTP APIs whose adapters are LOCAL REQUIRED: they only make network calls when their
 * credentials are configured, and throw a clear "credentials required" error otherwise.
 */
enum AiProvider: string
{
    case Fake = 'fake';
    case OpenAi = 'openai';
    case Anthropic = 'anthropic';
    case Gemini = 'gemini';
    case OpenRouter = 'openrouter';
    case Ollama = 'ollama';

    /** True for the local, network-free stub provider. */
    public function isFake(): bool
    {
        return $this === self::Fake;
    }

    /** Ollama runs against a local daemon and needs a base URL, not an API key. */
    public function isKeyless(): bool
    {
        return $this === self::Ollama || $this === self::Fake;
    }
}
