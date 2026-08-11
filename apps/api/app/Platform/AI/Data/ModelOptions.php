<?php

declare(strict_types=1);

namespace App\Platform\AI\Data;

/**
 * Provider-neutral call options. `model` is nullable so a caller can defer to the provider's
 * configured default; the manager/AiClient fills it in from config or the prompt's preference
 * before a provider ever sees it.
 */
final class ModelOptions
{
    public function __construct(
        public readonly ?string $model = null,
        public readonly float $temperature = 0.7,
        public readonly int $maxTokens = 1024,
        public readonly int $timeout = 30,
        public readonly int $retries = 2,
    ) {}

    /**
     * Build from the config('ai.defaults') block, tolerating missing keys.
     *
     * @param  array<string, mixed>  $defaults
     */
    public static function fromDefaults(array $defaults, ?string $model = null): self
    {
        return new self(
            model: $model,
            temperature: (float) ($defaults['temperature'] ?? 0.7),
            maxTokens: (int) ($defaults['max_tokens'] ?? 1024),
            timeout: (int) ($defaults['timeout'] ?? 30),
            retries: (int) ($defaults['retries'] ?? 2),
        );
    }

    public function withModel(?string $model): self
    {
        return new self($model, $this->temperature, $this->maxTokens, $this->timeout, $this->retries);
    }

    public function withMaxTokens(int $maxTokens): self
    {
        return new self($this->model, $this->temperature, $maxTokens, $this->timeout, $this->retries);
    }
}
