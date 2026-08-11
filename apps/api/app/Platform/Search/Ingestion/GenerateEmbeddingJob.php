<?php

declare(strict_types=1);

namespace App\Platform\Search\Ingestion;

use App\Platform\Shared\Tenancy\Concerns\TenantAware;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Async (re)embedding of a single source record on content change. Runs on the dedicated `search`
 * queue so embedding work never blocks the request path or latency-sensitive jobs.
 *
 * Tenant safety: captures the dispatching tenant at construct time and restores it on the worker
 * (TenantAware), so the adapter's chunksFor() reads the same tenant boundary the writer saw — an
 * org-private course re-indexes under its own org, a global course under none. The ingestion service
 * then stamps organization_id from the chunk, so the stored row is correct regardless.
 *
 * Idempotent: delegates to IngestionService::reindex, which delete-then-inserts this source's rows.
 */
final class GenerateEmbeddingJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use TenantAware;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(
        public readonly string $sourceType,
        public readonly int $sourceId,
    ) {
        $this->onQueue((string) config('search.queue', 'search'));
        $this->captureTenantContext();
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 60];
    }

    public function handle(IngestionService $ingestion): void
    {
        $ingestion->reindex($this->sourceType, $this->sourceId);
    }
}
