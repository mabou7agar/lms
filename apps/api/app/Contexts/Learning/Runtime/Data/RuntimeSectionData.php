<?php

namespace App\Contexts\Learning\Runtime\Data;

/**
 * Render-safe runtime projection of a curriculum section and its ordered lessons.
 */
final readonly class RuntimeSectionData
{
    /** @param list<RuntimeLessonData> $lessons */
    public function __construct(
        public string $publicId,
        public string $title,
        public array $lessons,
    ) {}
}
