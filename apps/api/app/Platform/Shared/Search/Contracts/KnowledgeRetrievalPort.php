<?php

declare(strict_types=1);

namespace App\Platform\Shared\Search\Contracts;

use App\Platform\Shared\Search\Data\RetrievedChunk;

/**
 * The single seam through which non-Search platform features (the AI tutor + instructor copilot) pull
 * grounding context for Retrieval-Augmented Generation, WITHOUT importing the Search platform module.
 *
 * It exists to break the AI -> Search dependency cycle: AI depends only on Shared + IdentityContracts,
 * so it consumes THIS Shared contract; Search implements it as a thin wrapper over its own hybrid
 * retriever (Search -> Shared, and Search -> AI are already permitted edges). A retrieval is always
 * confined by tenant (organizationId), audience (visibilities) and — critically for the tutor/copilot —
 * a single course, so an answer can never be grounded in another course's, tenant's, or unpublished
 * content. The caller decides the scope; the implementation only ever narrows, never widens it.
 */
interface KnowledgeRetrievalPort
{
    /**
     * Retrieve the most relevant grounding chunks for a query, confined to the given scope.
     *
     * @param  string  $query  the learner/instructor question or drafting brief
     * @param  int|null  $organizationId  active tenant id, or null for global/platform content
     * @param  list<string>  $visibilities  audience classes the caller may see (public|authenticated)
     * @param  list<string>  $sourceTypes  content kinds to include (course|lesson|qna); [] = all
     * @param  int|null  $courseId  confine retrieval to ONE course's content; null = unconfined
     * @param  int  $limit  maximum chunks to return
     * @return list<RetrievedChunk> ranked best-first
     */
    public function retrieve(
        string $query,
        ?int $organizationId,
        array $visibilities,
        array $sourceTypes,
        ?int $courseId,
        int $limit,
    ): array;
}
