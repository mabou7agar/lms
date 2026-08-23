<?php

declare(strict_types=1);

namespace App\Platform\AI\Providers;

use App\Platform\AI\AiProviderManager;
use App\Platform\AI\Contracts\EmbeddingModel;
use App\Platform\AI\Data\EmbeddingResult;
use App\Platform\AI\Data\ModelOptions;
use App\Platform\AI\Enums\AiProvider;

/** Defers fail-closed provider validation until an embedding operation is actually requested. */
final readonly class DeferredEmbeddingModel implements EmbeddingModel
{
    public function __construct(private AiProviderManager $providers) {}

    public function embed(array $texts, ModelOptions $options): EmbeddingResult
    {
        return $this->providers->embeddingModel()->embed($texts, $options);
    }

    public function provider(): AiProvider
    {
        return $this->providers->embeddingModel()->provider();
    }
}
