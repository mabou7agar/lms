<?php

declare(strict_types=1);

namespace App\Platform\Search\Ingestion;

use App\Platform\Shared\Search\Contracts\SearchIndexer;

/**
 * The real SearchIndexer (bound over Shared's NullSearchIndexer when the Search capability is
 * loaded). A content domain calls this hook on write; it queues async (re)embedding, and removes
 * index rows synchronously on delete/unpublish. Domains depend only on the Shared contract.
 */
final class QueueingSearchIndexer implements SearchIndexer
{
    public function __construct(
        private readonly IngestionService $ingestion,
    ) {}

    public function queueReindex(string $sourceType, int $id): void
    {
        GenerateEmbeddingJob::dispatch($sourceType, $id);
    }

    public function remove(string $sourceType, int $id): void
    {
        $this->ingestion->remove($sourceType, $id);
    }
}
