<?php

declare(strict_types=1);

namespace App\Platform\AI\Data;

/**
 * The provider-neutral result of an embedding call: one float vector per input text, plus token
 * accounting. `dimensions` is the length of each vector (embeddings are returned in-memory — the
 * platform DB has no pgvector, so persistence of vectors is a later feature's concern).
 */
final class EmbeddingResult
{
    /**
     * @param  list<list<float>>  $vectors
     */
    public function __construct(
        public readonly array $vectors,
        public readonly TokenUsage $usage,
        public readonly string $provider,
        public readonly string $model,
        public readonly int $dimensions,
    ) {}

    public function count(): int
    {
        return count($this->vectors);
    }
}
