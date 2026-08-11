<?php

declare(strict_types=1);

namespace App\Platform\Search\Contracts;

use App\Platform\Search\Data\VectorMatch;
use App\Platform\Search\Data\VectorQuery;

/**
 * Similarity-retrieval backend for the semantic search arm. Two implementations exist:
 *   - {@see \App\Platform\Search\Stores\PortableVectorStore} (default) — pre-filters candidates in
 *     SQL then scores cosine in PHP; needs no database extension (pgvector-OPTIONAL).
 *   - {@see \App\Platform\Search\Stores\PgVectorStore} — a guarded stub selected by
 *     config('search.vector.driver') = 'pgvector'; throws until the extension + ANN index are
 *     provisioned (LOCAL/INFRA required).
 *
 * The store is retrieval-only: writing rows is the ingestion layer's job.
 */
interface VectorStore
{
    /**
     * Return the top-$limit chunks most similar to $queryVector, restricted to the pre-filter and
     * ordered by descending cosine similarity.
     *
     * @param  list<float>  $queryVector  L2-normalised query embedding
     * @return list<VectorMatch>
     */
    public function similar(array $queryVector, VectorQuery $query, int $limit): array;
}
