<?php

declare(strict_types=1);

namespace App\Platform\Search\Stores;

use App\Platform\Search\Contracts\VectorStore;
use App\Platform\Search\Data\VectorQuery;
use RuntimeException;

/**
 * pgvector-backed similarity store — selected by config('search.vector.driver') = 'pgvector'.
 *
 * This is a GUARDED STUB by design: the platform's stock Postgres has NO pgvector extension, so
 * enabling this driver without provisioning the extension and the ANN-indexed `vector` column must
 * fail LOUD rather than silently return nothing. Standing it up is LOCAL / INFRA work:
 *   1. CREATE EXTENSION vector;  (superuser, per database)
 *   2. add a `vector(dims)` column + an ivfflat/hnsw index to content_embeddings (infra migration);
 *   3. backfill the vector column from the JSONB embedding;
 *   4. implement similar() as an ORDER BY embedding <=> :query LIMIT :k query with the same
 *      tenant/visibility/locale pre-filter as {@see PortableVectorStore}.
 *
 * Until then every call throws with an actionable message. Live embeddings + pgvector are LOCAL
 * REQUIRED; the portable driver is the supported default everywhere else.
 */
final class PgVectorStore implements VectorStore
{
    public function similar(array $queryVector, VectorQuery $query, int $limit): array
    {
        throw new RuntimeException(
            'pgvector vector store is not available: the pgvector extension + a vector column/ANN '
            .'index migration are required (LOCAL/INFRA). Provision pgvector and implement PgVectorStore, '
            ."or set config('search.vector.driver') back to 'portable'."
        );
    }
}
