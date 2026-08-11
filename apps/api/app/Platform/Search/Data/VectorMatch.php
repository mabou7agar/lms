<?php

declare(strict_types=1);

namespace App\Platform\Search\Data;

/**
 * One scored candidate returned by a VectorStore semantic query — the source record plus its cosine
 * similarity. Deduplication/fusion across a record's several chunks is the caller's concern; the
 * store returns per-chunk matches.
 */
final class VectorMatch
{
    public function __construct(
        public readonly string $embeddableType,
        public readonly int $embeddableId,
        public readonly ?string $embeddablePublicId,
        public readonly string $sourceType,
        public readonly ?string $title,
        public readonly string $chunkText,
        public readonly string $locale,
        /** Cosine similarity in [-1, 1]. */
        public readonly float $score,
    ) {}
}
