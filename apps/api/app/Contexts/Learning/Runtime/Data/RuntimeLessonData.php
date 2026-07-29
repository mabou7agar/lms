<?php

namespace App\Contexts\Learning\Runtime\Data;

use App\Contexts\Learning\Enums\LessonLockReason;

/**
 * Render-safe runtime projection of a single lesson in the curriculum view. Carries ONLY what a
 * learner client renders — public id, title, type, and computed learner state. No authoring-only
 * fields, no internal ids beyond what the resource maps, no media identifiers.
 */
final readonly class RuntimeLessonData
{
    public function __construct(
        public string $publicId,
        public string $title,
        public string $type,
        public bool $isPreview,
        public ?bool $hasMedia,
        public bool $completed,
        public bool $locked,
        public ?LessonLockReason $lockReason,
        public bool $prerequisitesMet,
        public bool $released,
        public ?string $availableAt,
        public ?int $estimatedDurationSeconds,
    ) {}
}
