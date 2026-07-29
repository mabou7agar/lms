<?php

namespace App\Domains\Authoring\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * P2/W03 - Scalar-only version lifecycle event (no Eloquent models in the payload).
 */
class ContentVersionCreated
{
    use Dispatchable;

    public function __construct(
        public readonly int $courseId,
        public readonly int $versionId,
        public readonly int $versionNumber,
        public readonly ?int $actorId,
    ) {}
}
