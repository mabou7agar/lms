<?php

declare(strict_types=1);

namespace App\Platform\Search\Console;

use App\Platform\Search\Ingestion\IngestionService;
use App\Platform\Shared\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * Rebuilds the whole content_embeddings index from every registered IndexableContentPort adapter.
 *
 * Runs with tenancy BYPASSED so it sees every tenant's + global content in one pass; the ingestion
 * service stamps each row's organization_id from the chunk, so cross-tenant isolation is preserved in
 * the stored rows even though the scan is unscoped. Uses the FAKE embedding provider by default — no
 * network. For incremental updates use the async GenerateEmbeddingJob via the SearchIndexer hook.
 */
final class BackfillSearchIndexCommand extends Command
{
    protected $signature = 'search:backfill';

    protected $description = 'Rebuild the semantic search index (content_embeddings) for all indexable content.';

    public function handle(IngestionService $ingestion, TenantContext $tenant): int
    {
        $this->info('Rebuilding content_embeddings index...');

        $written = $tenant->runWithoutTenancy(static fn (): int => $ingestion->rebuildAll());

        $this->info("Done. {$written} chunk(s) embedded.");

        return self::SUCCESS;
    }
}
