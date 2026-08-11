<?php

declare(strict_types=1);

namespace App\Platform\Search\Search;

/**
 * One fused, de-duplicated search result: a source record with its combined score and which arms
 * (semantic / keyword) matched it. Internal ids are carried for the caller to hydrate; the stable
 * public id + source type are what an API response should expose.
 */
final class SearchHit
{
    public function __construct(
        public readonly string $embeddableType,
        public readonly int $embeddableId,
        public readonly ?string $embeddablePublicId,
        public readonly string $sourceType,
        public readonly ?string $title,
        public readonly string $snippet,
        public readonly string $locale,
        public readonly float $score,
        public readonly bool $matchedSemantic,
        public readonly bool $matchedKeyword,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->embeddablePublicId,
            'source_type' => $this->sourceType,
            'title' => $this->title,
            'snippet' => $this->snippet,
            'locale' => $this->locale,
            'score' => round($this->score, 6),
            'matched' => array_values(array_filter([
                $this->matchedSemantic ? 'semantic' : null,
                $this->matchedKeyword ? 'keyword' : null,
            ])),
        ];
    }
}
