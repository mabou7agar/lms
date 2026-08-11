<?php

declare(strict_types=1);

namespace App\Platform\Shared\Search\Data;

use App\Platform\Shared\Search\Enums\SearchSourceType;
use App\Platform\Shared\Search\Enums\SearchVisibility;

/**
 * One indexable unit of content, produced by a domain's IndexableContentPort adapter and consumed
 * by the Search ingestion pipeline. Carries ONLY what the index needs — never private user data.
 *
 * The owning domain is responsible for guaranteeing that a chunk it emits is safe to index
 * (published + audience-appropriate). This DTO lives in Shared so both the producing domains and the
 * Search platform capability can reference it without a cross-context model dependency.
 */
final class IndexableChunk
{
    public function __construct(
        /** Stable string tag for the source model kind (e.g. "course", "lesson", "qna_answer"). */
        public readonly string $embeddableType,
        /** Internal id of the source record. */
        public readonly int $embeddableId,
        /** Public (external) id of the source record, so search results expose stable ids. */
        public readonly ?string $embeddablePublicId,
        /** Owning tenant/organization id, or null for GLOBAL/platform content. */
        public readonly ?int $organizationId,
        /** BCP-47-ish locale of this chunk, or "*" for a language-agnostic (folded) chunk. */
        public readonly string $locale,
        public readonly SearchSourceType $sourceType,
        public readonly SearchVisibility $visibility,
        /** Human title of the source (for result display); never used for access decisions. */
        public readonly ?string $title,
        /** The already-normalised, index-safe text (no HTML, no secrets). */
        public readonly string $chunkText,
        /** Monotonic content version; a newer version supersedes older rows for the same embeddable. */
        public readonly int $version,
        /** Ordinal of this chunk within its source (0-based). */
        public readonly int $chunkIndex = 0,
        /**
         * Owning course id, so retrieval can be confined to a single course (RAG tutor/copilot). For a
         * course chunk this is the course's own id; for a lesson/Q&A chunk it is the course the lesson
         * or question belongs to. Null when the content is not course-scoped.
         */
        public readonly ?int $courseId = null,
    ) {}
}
