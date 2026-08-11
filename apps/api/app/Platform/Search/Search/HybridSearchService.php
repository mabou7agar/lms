<?php

declare(strict_types=1);

namespace App\Platform\Search\Search;

use App\Platform\AI\Contracts\EmbeddingModel;
use App\Platform\AI\Data\ModelOptions;
use App\Platform\Search\Contracts\VectorStore;
use App\Platform\Search\Data\VectorMatch;
use App\Platform\Search\Data\VectorQuery;
use App\Platform\Search\Models\ContentEmbedding;
use App\Platform\Search\Support\TextCanonicalizer;

/**
 * Hybrid retrieval: fuses a SEMANTIC arm (cosine over the query embedding, via the VectorStore) with
 * a KEYWORD arm (normalised substring match over content_embeddings.chunk_text) using a weighted sum.
 *
 * Both arms share the SAME VectorQuery pre-filter, so tenant + visibility + locale isolation is
 * enforced identically and a public search can never surface authenticated/private or another
 * tenant's content. The query is Arabic-normalised before either arm runs (bilingual folding), so an
 * Arabic query matches regardless of diacritics/letter-form/digit-script.
 *
 * Why hybrid beats keyword-only: the keyword arm needs a literal substring, so a reordered paraphrase
 * ("programming advanced python" vs the stored "advanced python programming") misses; the semantic
 * arm embeds the canonicalised (sorted-token) query and still matches. Fusion keeps both wins.
 */
final class HybridSearchService
{
    public function __construct(
        private readonly EmbeddingModel $embeddingModel,
        private readonly VectorStore $vectorStore,
        private readonly TextCanonicalizer $canonicalizer,
    ) {}

    /**
     * @param  float|null  $semanticWeight  override config; 0 disables the semantic arm (keyword-only)
     * @param  float|null  $keywordWeight  override config; 0 disables the keyword arm (semantic-only)
     * @return list<SearchHit>
     */
    public function search(
        string $query,
        VectorQuery $filters,
        ?int $limit = null,
        ?float $semanticWeight = null,
        ?float $keywordWeight = null,
    ): array {
        $normalized = $this->canonicalizer->normalize($query);
        $limit = $this->clampLimit($limit);

        if (mb_strlen($normalized) < (int) config('search.limits.min_query_length', 2)) {
            return [];
        }

        $semanticWeight ??= (float) config('search.hybrid.semantic_weight', 0.6);
        $keywordWeight ??= (float) config('search.hybrid.keyword_weight', 0.4);

        // Pull a wider candidate window per arm than the final limit so fusion has material to rank.
        $window = max($limit * 5, $limit);

        /** @var array<string, SearchHit> $fused */
        $fused = [];

        if ($semanticWeight > 0.0) {
            foreach ($this->semanticArm($query, $filters, $window) as $match) {
                $this->accumulate($fused, $this->keyFor($match->embeddableType, $match->embeddableId), [
                    'type' => $match->embeddableType,
                    'id' => $match->embeddableId,
                    'public_id' => $match->embeddablePublicId,
                    'source_type' => $match->sourceType,
                    'title' => $match->title,
                    'snippet' => $match->chunkText,
                    'locale' => $match->locale,
                    'semantic' => $semanticWeight * $match->score,
                    'keyword' => 0.0,
                ]);
            }
        }

        if ($keywordWeight > 0.0) {
            foreach ($this->keywordArm($normalized, $filters, $window) as $row) {
                $this->accumulate($fused, $this->keyFor($row->embeddable_type, $row->embeddable_id), [
                    'type' => $row->embeddable_type,
                    'id' => $row->embeddable_id,
                    'public_id' => $row->embeddable_public_id,
                    'source_type' => $row->source_type,
                    'title' => $row->title,
                    'snippet' => $row->chunk_text,
                    'locale' => $row->locale,
                    'semantic' => 0.0,
                    'keyword' => $keywordWeight * 1.0,
                ]);
            }
        }

        $hits = array_values($fused);

        usort($hits, static function (SearchHit $a, SearchHit $b): int {
            return [$b->score, $a->embeddableType, $a->embeddableId]
                <=> [$a->score, $b->embeddableType, $b->embeddableId];
        });

        return array_slice($hits, 0, $limit);
    }

    /**
     * @return list<VectorMatch>
     */
    private function semanticArm(string $query, VectorQuery $filters, int $window): array
    {
        $embedded = $this->canonicalizer->forEmbedding($query);

        /** @var array<string, mixed> $defaults */
        $defaults = (array) config('ai.defaults', []);
        $result = $this->embeddingModel->embed([$embedded], ModelOptions::fromDefaults($defaults));

        $vector = $result->vectors[0] ?? [];

        return $this->vectorStore->similar($vector, $filters, $window);
    }

    /**
     * @return list<ContentEmbedding>
     */
    private function keywordArm(string $normalizedQuery, VectorQuery $filters, int $window): array
    {
        // Escape LIKE wildcards so user metacharacters match literally (mirrors CourseSearchService).
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $normalizedQuery);

        /** @var list<ContentEmbedding> $rows */
        $rows = ContentEmbedding::query()
            ->forQuery($filters)
            ->where('chunk_text', 'like', '%'.$escaped.'%')
            ->orderBy('id')
            ->limit($window)
            ->get()
            ->all();

        return $rows;
    }

    /**
     * Merge an arm's contribution into the fused map, keeping the running score and matched-arm flags.
     *
     * @param  array<string, SearchHit>  $fused
     * @param  array<string, mixed>  $c
     */
    private function accumulate(array &$fused, string $key, array $c): void
    {
        $existing = $fused[$key] ?? null;
        $prevScore = $existing?->score ?? 0.0;

        $fused[$key] = new SearchHit(
            embeddableType: (string) $c['type'],
            embeddableId: (int) $c['id'],
            embeddablePublicId: $c['public_id'] !== null ? (string) $c['public_id'] : null,
            sourceType: (string) $c['source_type'],
            title: $c['title'] !== null ? (string) $c['title'] : ($existing?->title),
            snippet: $existing?->snippet ?? (string) $c['snippet'],
            locale: $existing?->locale ?? (string) $c['locale'],
            score: $prevScore + (float) $c['semantic'] + (float) $c['keyword'],
            matchedSemantic: ($existing?->matchedSemantic ?? false) || (float) $c['semantic'] > 0.0,
            matchedKeyword: ($existing?->matchedKeyword ?? false) || (float) $c['keyword'] > 0.0,
        );
    }

    private function keyFor(string $type, int $id): string
    {
        return $type.'#'.$id;
    }

    private function clampLimit(?int $limit): int
    {
        $default = (int) config('search.limits.default_limit', 20);
        $max = (int) config('search.limits.max_limit', 50);
        $limit ??= $default;

        return max(1, min($limit, $max));
    }
}
