<?php

declare(strict_types=1);

namespace App\Platform\AI\Governance;

use App\Platform\AI\Enums\AiProvider;
use App\Platform\AI\Exceptions\ModelNotAllowedException;

/**
 * The allow-list of models per provider plus their capabilities, from config('ai.models'). A model
 * a caller asks for must be registered here or the request fails closed (ModelNotAllowedException),
 * so no code can reach an unvetted or unbudgeted model. Capabilities (chat/embedding) let callers
 * check support before dispatching.
 */
final class ModelRegistry
{
    /**
     * @return array<string, array{chat?: bool, embedding?: bool}>
     */
    public function models(AiProvider $provider): array
    {
        /** @var array<string, array{chat?: bool, embedding?: bool}> $models */
        $models = (array) config('ai.models.'.$provider->value, []);

        return $models;
    }

    /** @return list<string> */
    public function allowedModels(AiProvider $provider): array
    {
        return array_keys($this->models($provider));
    }

    public function isAllowed(AiProvider $provider, string $model): bool
    {
        return array_key_exists($model, $this->models($provider));
    }

    public function assertAllowed(AiProvider $provider, string $model): void
    {
        if (! $this->isAllowed($provider, $model)) {
            throw new ModelNotAllowedException($provider->value, $model);
        }
    }

    public function supportsChat(AiProvider $provider, string $model): bool
    {
        return (bool) ($this->models($provider)[$model]['chat'] ?? false);
    }

    public function supportsEmbedding(AiProvider $provider, string $model): bool
    {
        return (bool) ($this->models($provider)[$model]['embedding'] ?? false);
    }

    /**
     * @return array{chat: bool, embedding: bool}
     */
    public function capabilities(AiProvider $provider, string $model): array
    {
        return [
            'chat' => $this->supportsChat($provider, $model),
            'embedding' => $this->supportsEmbedding($provider, $model),
        ];
    }
}
