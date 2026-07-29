<?php

namespace App\Domains\Authoring\Events;

use Illuminate\Foundation\Events\Dispatchable;

class ContentVersionForked
{
    use Dispatchable;

    public function __construct(
        public readonly int $sourceCourseId,
        public readonly int $sourceVersionId,
        public readonly int $destinationCourseId,
        public readonly int $newVersionId,
        public readonly ?int $actorId,
    ) {}
}
