<?php

declare(strict_types=1);

namespace App\Platform\Shared\Search\Adapters;

use App\Platform\Shared\Search\Contracts\SearchIndexer;

/**
 * Default no-op SearchIndexer. Bound in Shared so a content domain may call the hook unconditionally
 * even when the Search capability is not registered. The real QueueingSearchIndexer (Search) replaces
 * this binding when SearchServiceProvider is loaded.
 */
final class NullSearchIndexer implements SearchIndexer
{
    public function queueReindex(string $sourceType, int $id): void
    {
        // Intentionally empty: no search capability loaded.
    }

    public function remove(string $sourceType, int $id): void
    {
        // Intentionally empty: no search capability loaded.
    }
}
