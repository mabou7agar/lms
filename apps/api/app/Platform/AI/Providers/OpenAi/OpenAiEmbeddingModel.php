<?php

declare(strict_types=1);

namespace App\Platform\AI\Providers\OpenAi;

use App\Platform\AI\Contracts\EmbeddingModel;
use App\Platform\AI\Data\EmbeddingResult;
use App\Platform\AI\Data\ModelOptions;
use App\Platform\AI\Data\TokenUsage;
use App\Platform\AI\Enums\AiProvider;
use App\Platform\AI\Exceptions\AiException;
use App\Platform\AI\Providers\Http\GuardsCredentials;
use Illuminate\Http\Client\Factory;

/**
 * OpenAI Embeddings adapter (LOCAL REQUIRED). Maps to POST {base}/embeddings. Makes no network call
 * unless OPENAI_API_KEY is configured.
 */
class OpenAiEmbeddingModel implements EmbeddingModel
{
    use GuardsCredentials;

    /** @param array<string, mixed> $config */
    public function __construct(
        protected readonly Factory $http,
        protected readonly array $config,
    ) {}

    public function provider(): AiProvider
    {
        return AiProvider::OpenAi;
    }

    public function embed(array $texts, ModelOptions $options): EmbeddingResult
    {
        $apiKey = $this->requireString($this->config, 'api_key', $this->provider(), 'OPENAI_API_KEY');
        $model = $options->model ?? $this->stringConfig($this->config, 'embedding_model', 'text-embedding-3-small');
        $base = rtrim($this->stringConfig($this->config, 'base_url', 'https://api.openai.com/v1'), '/');

        $response = $this->http
            ->timeout($options->timeout)
            ->retry(max(1, $options->retries + 1), 200, null, false)
            ->withHeaders(['Authorization' => 'Bearer '.$apiKey])
            ->post($base.'/embeddings', ['model' => $model, 'input' => $texts]);

        if ($response->failed()) {
            throw new AiException(sprintf('AI provider [%s] embedding request failed (HTTP %d).', $this->provider()->value, $response->status()));
        }

        $vectors = [];
        /** @var array<int, array<string, mixed>> $data */
        $data = (array) $response->json('data', []);
        foreach ($data as $row) {
            /** @var list<float> $vector */
            $vector = array_map(static fn ($v): float => (float) $v, (array) ($row['embedding'] ?? []));
            $vectors[] = $vector;
        }

        return new EmbeddingResult(
            vectors: $vectors,
            usage: new TokenUsage((int) ($response->json('usage.prompt_tokens') ?? 0), 0),
            provider: $this->provider()->value,
            model: (string) ($response->json('model') ?? $model),
            dimensions: $vectors !== [] ? count($vectors[0]) : 0,
        );
    }
}
