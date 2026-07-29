<?php

namespace App\Domains\Authoring\Events;

use Illuminate\Foundation\Events\Dispatchable;

class ContentVersionCloned
{
    use Dispatchable;

    public function __construct(
        public readonly int $courseId,
        public readonly int $newVersionId,
        public readonly int $sourceVersionId,
        public readonly ?int $actorId,
    ) {}
}
