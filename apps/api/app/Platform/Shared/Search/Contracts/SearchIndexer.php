<?php

declare(strict_types=1);

namespace App\Platform\Shared\Search\Contracts;

use App\Platform\Shared\Search\Adapters\NullSearchIndexer;

/**
 * Write-side hook a content-owning domain calls when one of its records changes, so the search index
 * stays fresh — WITHOUT the domain importing anything from the Search capability. Search binds the
 * real implementation; when Search is not loaded the {@see NullSearchIndexer}
 * default makes every call a no-op, so a domain can call it unconditionally.
 *
 * Integration (owning domains): call queueReindex() from a model's saved()/published() path and
 * remove() from its deleted() path. Both are idempotent and tenant-safe.
 */
interface SearchIndexer
{
    /** Queue (re)embedding of one source record (async). `$sourceType` is course|lesson|qna. */
    public function queueReindex(string $sourceType, int $id): void;

    /** Remove all index rows for one source record (synchronous; safe if none exist). */
    public function remove(string $sourceType, int $id): void;
}
