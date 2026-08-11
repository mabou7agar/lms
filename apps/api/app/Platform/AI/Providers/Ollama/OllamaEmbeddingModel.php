<?php

declare(strict_types=1);

namespace App\Platform\AI\Providers\Ollama;

use App\Platform\AI\Contracts\EmbeddingModel;
use App\Platform\AI\Data\EmbeddingResult;
use App\Platform\AI\Data\ModelOptions;
use App\Platform\AI\Data\TokenUsage;
use App\Platform\AI\Enums\AiProvider;
use App\Platform\AI\Exceptions\AiException;
use App\Platform\AI\Providers\Http\GuardsCredentials;
use App\Platform\AI\Support\TokenEstimator;
use Illuminate\Http\Client\Factory;

/**
 * Ollama embeddings adapter (LOCAL REQUIRED). Maps to POST {base}/api/embeddings, one request per
 * input text. Needs a reachable base URL, not an API key. Makes no network call unless
 * AI_OLLAMA_BASE_URL is configured.
 */
final class OllamaEmbeddingModel implements EmbeddingModel
{
    use GuardsCredentials;

    /** @param array<string, mixed> $config */
    public function __construct(
        private readonly Factory $http,
        private readonly TokenEstimator $estimator,
        private readonly array $config,
    ) {}

    public function provider(): AiProvider
    {
        return AiProvider::Ollama;
    }

    public function embed(array $texts, ModelOptions $options): EmbeddingResult
    {
        $base = rtrim($this->requireString($this->config, 'base_url', $this->provider(), 'AI_OLLAMA_BASE_URL'), '/');
        $model = $options->model ?? $this->stringConfig($this->config, 'embedding_model', 'nomic-embed-text');

        $vectors = [];
        $inputTokens = 0;

        foreach ($texts as $text) {
            $response = $this->http
                ->timeout($options->timeout)
                ->retry(max(1, $options->retries + 1), 200, null, false)
                ->post($base.'/api/embeddings', ['model' => $model, 'prompt' => $text]);

            if ($response->failed()) {
                throw new AiException(sprintf('AI provider [%s] embedding request failed (HTTP %d).', $this->provider()->value, $response->status()));
            }

            /** @var list<float> $vector */
            $vector = array_map(static fn ($v): float => (float) $v, (array) $response->json('embedding', []));
            $vectors[] = $vector;
            $inputTokens += $this->estimator->estimate($text);
        }

        return new EmbeddingResult(
            vectors: $vectors,
            usage: new TokenUsage($inputTokens, 0),
            provider: $this->provider()->value,
            model: $model,
            dimensions: $vectors !== [] ? count($vectors[0]) : 0,
        );
    }
}
