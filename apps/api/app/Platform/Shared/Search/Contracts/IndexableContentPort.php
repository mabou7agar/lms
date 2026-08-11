<?php

declare(strict_types=1);

namespace App\Platform\Shared\Search\Contracts;

use App\Platform\Shared\Search\Data\IndexableChunk;

/**
 * Cross-context port DECLARED in Shared and IMPLEMENTED by each content-owning domain (Catalog,
 * Authoring, Qna). It lets the Search platform capability pull indexable content WITHOUT importing
 * any domain model — the domain adapter is the only code that touches its own Eloquent models.
 *
 * Contract for implementers:
 *   - Return ONLY published/authorised content. NEVER emit chunks for unpublished, private,
 *     personal, graded, payment or secret data.
 *   - {@see indexableIds()} enumerates the ids currently eligible for indexing (drives backfill).
 *   - {@see chunksFor()} maps one id to its chunks (drives per-record (re)indexing). It returns an
 *     empty array when the id is no longer eligible (unpublished/deleted), which the ingestion layer
 *     treats as "remove any existing rows".
 *
 * Each adapter is registered in the container under the `search.indexers` tag by its own domain
 * provider, so the ingestion service discovers every source without referencing any of them.
 */
interface IndexableContentPort
{
    /** Stable source classification this adapter owns (course|lesson|qna). */
    public function sourceType(): string;

    /**
     * All currently-indexable source ids for this adapter (published + authorised only).
     *
     * @return list<int>
     */
    public function indexableIds(): array;

    /**
     * Chunks for a single source id. Empty when the id is not (or no longer) indexable.
     *
     * @return list<IndexableChunk>
     */
    public function chunksFor(int $id): array;
}
