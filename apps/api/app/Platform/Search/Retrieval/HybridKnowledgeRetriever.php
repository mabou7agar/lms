<?php

declare(strict_types=1);

namespace App\Platform\Search\Retrieval;

use App\Platform\Search\Data\VectorQuery;
use App\Platform\Search\Search\HybridSearchService;
use App\Platform\Search\Search\SearchHit;
use App\Platform\Shared\Search\Contracts\KnowledgeRetrievalPort;
use App\Platform\Shared\Search\Data\RetrievedChunk;

/**
 * Search's implementation of the Shared {@see KnowledgeRetrievalPort}: a thin wrapper over the
 * existing {@see HybridSearchService} that AI features (tutor, copilot) consume WITHOUT importing any
 * Search internal.
 *
 * It only ever NARROWS the retrieval: the caller-supplied tenant, audience (visibilities), source
 * kinds and — critically — a single course id are packed into a {@see VectorQuery}, whose pre-filter
 * is applied identically to BOTH the semantic and keyword arms (so course + tenant + visibility
 * isolation is enforced in one choke point). Hits are mapped to boundary-safe {@see RetrievedChunk}
 * DTOs; the internal SearchHit never leaves this module.
 */
final class HybridKnowledgeRetriever implements KnowledgeRetrievalPort
{
    public function __construct(
        private readonly HybridSearchService $search,
    ) {}

    public function retrieve(
        string $query,
        ?int $organizationId,
        array $visibilities,
        array $sourceTypes,
        ?int $courseId,
        int $limit,
    ): array {
        $filters = new VectorQuery(
            organizationId: $organizationId,
            visibilities: array_values($visibilities),
            locales: [],
            sourceTypes: array_values($sourceTypes),
            courseId: $courseId,
        );

        $hits = $this->search->search($query, $filters, $limit);

        return array_map(
            static fn (SearchHit $hit): RetrievedChunk => new RetrievedChunk(
                sourceType: $hit->sourceType,
                embeddableId: $hit->embeddableId,
                embeddablePublicId: $hit->embeddablePublicId,
                title: $hit->title,
                snippet: $hit->snippet,
                score: $hit->score,
            ),
            $hits,
        );
    }
}
