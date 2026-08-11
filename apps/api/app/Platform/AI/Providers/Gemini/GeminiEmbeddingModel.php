<?php

declare(strict_types=1);

namespace App\Platform\AI\Providers\Gemini;

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
 * Google Gemini embeddings adapter (LOCAL REQUIRED). Maps to
 * POST {base}/models/{model}:batchEmbedContents?key=API_KEY. Makes no network call unless
 * GEMINI_API_KEY is configured. Gemini does not return token usage for embeddings, so input tokens
 * are estimated locally for metering.
 */
final class GeminiEmbeddingModel implements EmbeddingModel
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
        return AiProvider::Gemini;
    }

    public function embed(array $texts, ModelOptions $options): EmbeddingResult
    {
        $apiKey = $this->requireString($this->config, 'api_key', $this->provider(), 'GEMINI_API_KEY');
        $model = $options->model ?? $this->stringConfig($this->config, 'embedding_model', 'text-embedding-004');
        $base = rtrim($this->stringConfig($this->config, 'base_url', 'https://generativelanguage.googleapis.com/v1beta'), '/');

        $requests = array_map(static fn (string $text): array => [
            'model' => 'models/'.$model,
            'content' => ['parts' => [['text' => $text]]],
        ], $texts);

        $response = $this->http
            ->timeout($options->timeout)
            ->retry(max(1, $options->retries + 1), 200, null, false)
            ->post($base.'/models/'.$model.':batchEmbedContents?key='.$apiKey, ['requests' => $requests]);

        if ($response->failed()) {
            throw new AiException(sprintf('AI provider [%s] embedding request failed (HTTP %d).', $this->provider()->value, $response->status()));
        }

        $vectors = [];
        /** @var array<int, array<string, mixed>> $embeddings */
        $embeddings = (array) $response->json('embeddings', []);
        foreach ($embeddings as $embedding) {
            /** @var list<float> $vector */
            $vector = array_map(static fn ($v): float => (float) $v, (array) ($embedding['values'] ?? []));
            $vectors[] = $vector;
        }

        $inputTokens = 0;
        foreach ($texts as $text) {
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
