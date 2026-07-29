<?php

namespace App\Domains\Authoring\Events;

use Illuminate\Foundation\Events\Dispatchable;

class ContentVersionRestored
{
    use Dispatchable;

    public function __construct(
        public readonly int $courseId,
        public readonly int $restoredVersionId,
        public readonly int $safetyVersionId,
        public readonly ?int $actorId,
    ) {}
}
