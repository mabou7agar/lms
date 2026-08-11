<?php

declare(strict_types=1);

namespace App\Platform\AI\Providers\Fake;

use App\Platform\AI\Contracts\EmbeddingModel;
use App\Platform\AI\Data\EmbeddingResult;
use App\Platform\AI\Data\ModelOptions;
use App\Platform\AI\Data\TokenUsage;
use App\Platform\AI\Enums\AiProvider;
use App\Platform\AI\Support\TokenEstimator;

/**
 * Deterministic, network-free embedding model. Each vector is a hashed pseudo-embedding derived
 * from the input text (SHA-256 seeded), L2-normalised — so the SAME text always yields the SAME
 * vector and DIFFERENT texts yield different ones. This lets semantic-search plumbing be built and
 * tested end-to-end without any credentials or pgvector, while remaining fully reproducible.
 */
final class FakeEmbeddingModel implements EmbeddingModel
{
    public function __construct(
        private readonly TokenEstimator $estimator,
        private readonly string $model = 'fake-embed-v1',
        private readonly int $dimensions = 128,
    ) {}

    public function embed(array $texts, ModelOptions $options): EmbeddingResult
    {
        $vectors = [];
        $inputTokens = 0;

        foreach ($texts as $text) {
            $vectors[] = $this->pseudoVector($text);
            $inputTokens += $this->estimator->estimate($text);
        }

        return new EmbeddingResult(
            vectors: $vectors,
            usage: new TokenUsage($inputTokens, 0),
            provider: AiProvider::Fake->value,
            model: $options->model ?? $this->model,
            dimensions: $this->dimensions,
        );
    }

    public function provider(): AiProvider
    {
        return AiProvider::Fake;
    }

    /**
     * A deterministic, L2-normalised pseudo-vector for a text.
     *
     * @return list<float>
     */
    private function pseudoVector(string $text): array
    {
        $vector = [];
        $sumSquares = 0.0;

        for ($i = 0; $i < $this->dimensions; $i++) {
            // Seed each component with a distinct hash of (index, text) → stable across runs.
            $hash = hash('sha256', $i.':'.$text);
            $slice = substr($hash, 0, 8);
            $intVal = (int) hexdec($slice);
            // Map to [-1, 1).
            $component = ($intVal / 0xFFFFFFFF) * 2.0 - 1.0;
            $vector[] = $component;
            $sumSquares += $component * $component;
        }

        $norm = sqrt($sumSquares);

        if ($norm <= 0.0) {
            return $vector;
        }

        return array_map(static fn (float $c): float => $c / $norm, $vector);
    }
}
