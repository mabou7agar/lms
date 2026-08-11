<?php

declare(strict_types=1);

namespace App\Platform\Search\Stores;

use App\Platform\Search\Contracts\VectorStore;
use App\Platform\Search\Data\VectorMatch;
use App\Platform\Search\Data\VectorQuery;
use App\Platform\Search\Models\ContentEmbedding;

/**
 * Default, portable (pgvector-OPTIONAL) similarity store.
 *
 * Strategy: PRE-FILTER in SQL (tenant / visibility / locale / source_type) to a bounded candidate
 * set, then compute cosine similarity in PHP over that set. Embeddings are stored L2-normalised, so
 * cosine reduces to a dot product. This needs no database extension and is correct on the platform's
 * stock Postgres.
 *
 * SCALING LIMIT (documented, deliberate): cosine is computed in PHP, so cost is
 * O(candidates * dims). It is fine at catalogue scale because the pre-filter is selective and
 * `search.vector.max_candidates` caps the working set. It is NOT a nearest-neighbour index — when
 * the per-tenant candidate count routinely approaches the cap, switch config('search.vector.driver')
 * to 'pgvector' (LOCAL/INFRA) for an ANN index. Ranking semantics are identical across drivers.
 */
final class PortableVectorStore implements VectorStore
{
    public function similar(array $queryVector, VectorQuery $query, int $limit): array
    {
        if ($queryVector === [] || $limit < 1) {
            return [];
        }

        $minSimilarity = (float) config('search.vector.min_similarity', 0.35);
        $maxCandidates = (int) config('search.vector.max_candidates', 2000);

        /** @var list<ContentEmbedding> $candidates */
        $candidates = ContentEmbedding::query()
            ->forQuery($query)
            ->orderBy('id')
            ->limit($maxCandidates)
            ->get()
            ->all();

        $scored = [];
        foreach ($candidates as $row) {
            $vector = $row->embedding;
            if (! is_array($vector) || $vector === []) {
                continue;
            }

            $score = $this->cosine($queryVector, $vector);
            if ($score < $minSimilarity) {
                continue;
            }

            $scored[] = new VectorMatch(
                embeddableType: $row->embeddable_type,
                embeddableId: $row->embeddable_id,
                embeddablePublicId: $row->embeddable_public_id,
                sourceType: $row->source_type,
                title: $row->title,
                chunkText: $row->chunk_text,
                locale: $row->locale,
                score: $score,
            );
        }

        // Descending score; deterministic tie-break by (embeddable_type, embeddable_id).
        usort($scored, static function (VectorMatch $a, VectorMatch $b): int {
            return [$b->score, $a->embeddableType, $a->embeddableId]
                <=> [$a->score, $b->embeddableType, $b->embeddableId];
        });

        return array_slice($scored, 0, $limit);
    }

    /**
     * Cosine similarity. Vectors are stored L2-normalised, so this is a dot product; we still divide
     * by the norms defensively so a mis-normalised or truncated stored vector cannot skew ranking.
     *
     * @param  list<float>  $a
     * @param  list<float>  $b
     */
    private function cosine(array $a, array $b): float
    {
        $n = min(count($a), count($b));
        if ($n === 0) {
            return 0.0;
        }

        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        for ($i = 0; $i < $n; $i++) {
            $x = (float) $a[$i];
            $y = (float) $b[$i];
            $dot += $x * $y;
            $normA += $x * $x;
            $normB += $y * $y;
        }

        if ($normA <= 0.0 || $normB <= 0.0) {
            return 0.0;
        }

        return $dot / (sqrt($normA) * sqrt($normB));
    }
}
