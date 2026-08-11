<?php

declare(strict_types=1);

namespace App\Platform\Search\Ingestion;

use App\Platform\AI\Contracts\EmbeddingModel;
use App\Platform\AI\Data\ModelOptions;
use App\Platform\Search\Models\ContentEmbedding;
use App\Platform\Search\Support\TextCanonicalizer;
use App\Platform\Shared\Search\Contracts\IndexableContentPort;
use App\Platform\Shared\Search\Data\IndexableChunk;
use Illuminate\Support\Facades\DB;

/**
 * Pulls indexable content from the registered {@see IndexableContentPort} adapters, embeds each chunk
 * through the AI EmbeddingModel (the deterministic FAKE provider by default — no network), and upserts
 * the rows into content_embeddings. Tenant, visibility and version are taken from the chunk itself, so
 * a global course keeps organization_id NULL even when a tenant context is active during backfill.
 *
 * Idempotent: re-indexing a source replaces exactly its own rows (delete-then-insert inside a
 * transaction, keyed by source_type + embeddable_id). Re-indexing a no-longer-eligible source (the
 * adapter returns no chunks) simply removes its rows.
 */
final class IngestionService
{
    /**
     * @param  list<IndexableContentPort>  $indexers
     */
    public function __construct(
        private readonly EmbeddingModel $embeddingModel,
        private readonly TextCanonicalizer $canonicalizer,
        private readonly array $indexers,
    ) {}

    /**
     * Backfill EVERY registered source. Returns the number of chunks written. Callers that must see
     * all tenants' content should wrap this in TenantContext::runWithoutTenancy().
     */
    public function rebuildAll(): int
    {
        $written = 0;
        foreach ($this->indexers as $indexer) {
            foreach ($indexer->indexableIds() as $id) {
                $written += $this->reindex($indexer->sourceType(), (int) $id);
            }
        }

        return $written;
    }

    /**
     * (Re)index one source record. Returns the number of chunks written (0 removes the source).
     */
    public function reindex(string $sourceType, int $id): int
    {
        $indexer = $this->indexerFor($sourceType);
        if ($indexer === null) {
            return 0;
        }

        $chunks = $indexer->chunksFor($id);

        /** @var int $written */
        $written = DB::transaction(function () use ($sourceType, $id, $chunks): int {
            ContentEmbedding::query()
                ->where('source_type', $sourceType)
                ->where('embeddable_id', $id)
                ->delete();

            if ($chunks === []) {
                return 0;
            }

            $this->insertChunks($chunks);

            return count($chunks);
        });

        return $written;
    }

    /** Remove all rows for a source record (content deleted/unpublished). Returns rows removed. */
    public function remove(string $sourceType, int $id): int
    {
        return ContentEmbedding::query()
            ->where('source_type', $sourceType)
            ->where('embeddable_id', $id)
            ->delete();
    }

    /**
     * Embed a batch of chunks in ONE provider call and insert them. Chunk index is re-based to the
     * chunk's ordinal within THIS source so the (source_type, embeddable_id, chunk_index) unique key
     * holds regardless of what the adapter set.
     *
     * @param  list<IndexableChunk>  $chunks
     */
    private function insertChunks(array $chunks): void
    {
        $texts = array_map(
            fn (IndexableChunk $c): string => $this->canonicalizer->forEmbedding($c->chunkText),
            $chunks,
        );

        $result = $this->embeddingModel->embed(array_values($texts), $this->options());
        $now = now();

        $rows = [];
        foreach (array_values($chunks) as $i => $chunk) {
            $vector = $result->vectors[$i] ?? [];

            $rows[] = [
                'embeddable_type' => $chunk->embeddableType,
                'embeddable_id' => $chunk->embeddableId,
                'embeddable_public_id' => $chunk->embeddablePublicId,
                'organization_id' => $chunk->organizationId,
                'course_id' => $chunk->courseId,
                'locale' => $chunk->locale,
                'source_type' => $chunk->sourceType->value,
                'visibility' => $chunk->visibility->value,
                'chunk_index' => $i,
                'title' => $chunk->title,
                'chunk_text' => $this->canonicalizer->normalize($chunk->chunkText),
                'embedding' => json_encode($vector, JSON_THROW_ON_ERROR),
                'dims' => $result->dimensions,
                'model' => $result->model,
                'version' => $chunk->version,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        ContentEmbedding::query()->insert($rows);
    }

    private function options(): ModelOptions
    {
        /** @var array<string, mixed> $defaults */
        $defaults = (array) config('ai.defaults', []);

        return ModelOptions::fromDefaults($defaults);
    }

    private function indexerFor(string $sourceType): ?IndexableContentPort
    {
        foreach ($this->indexers as $indexer) {
            if ($indexer->sourceType() === $sourceType) {
                return $indexer;
            }
        }

        return null;
    }
}
