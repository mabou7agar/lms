<?php

declare(strict_types=1);

namespace App\Platform\Shared\Search\Data;

/**
 * A single grounding chunk returned by the {@see \App\Platform\Shared\Search\Contracts\KnowledgeRetrievalPort}.
 *
 * This is the boundary-safe projection of a search hit that AI features (tutor, copilot) consume as
 * RAG context and expose as CITATIONS. It carries only display/attribution fields — never a raw
 * embedding, never access-control state — so a feature outside Search can render a grounded answer
 * without importing any Search internal (SearchHit) or content-domain model. The producing side
 * (Search) is responsible for having already applied tenant + visibility + course scoping.
 */
final readonly class RetrievedChunk
{
    public function __construct(
        /** Content kind this chunk came from: course | lesson | qna. */
        public string $sourceType,
        /** Internal id of the source record (never exposed in payloads; use $embeddablePublicId). */
        public int $embeddableId,
        /** Stable external id of the source record — the id a citation should expose. */
        public ?string $embeddablePublicId,
        /** Human title of the source, for citation display. */
        public ?string $title,
        /** The retrieved, index-safe text snippet used to ground the answer. */
        public string $snippet,
        /** Combined relevance score from the hybrid retriever. */
        public float $score,
    ) {}

    /**
     * Citation shape for an API payload: only the stable public id + display fields, never the
     * internal id.
     *
     * @return array<string, mixed>
     */
    public function toCitation(): array
    {
        return [
            'id' => $this->embeddablePublicId,
            'source_type' => $this->sourceType,
            'title' => $this->title,
            'snippet' => $this->snippet,
            'score' => round($this->score, 6),
        ];
    }
}
