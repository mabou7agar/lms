<?php

declare(strict_types=1);

namespace App\Platform\Search\Data;

/**
 * The immutable pre-filter for a vector/keyword read: WHO is asking (tenant), WHAT audience they may
 * see (visibilities), in WHICH languages (locales) and over WHICH content kinds (sourceTypes).
 * Shared by both the semantic (VectorStore) and keyword arms so isolation is enforced identically.
 */
final class VectorQuery
{
    /**
     * @param  list<string>  $visibilities  allowed audience classes (public|authenticated|private)
     * @param  list<string>  $locales  requested locales ('*' is always additionally matched)
     * @param  list<string>  $sourceTypes  restrict to these source kinds; [] = all
     * @param  int|null  $courseId  confine the read to a single course's chunks; null = all courses
     */
    public function __construct(
        public readonly ?int $organizationId,
        public readonly array $visibilities,
        public readonly array $locales = [],
        public readonly array $sourceTypes = [],
        public readonly ?int $courseId = null,
    ) {}
}
