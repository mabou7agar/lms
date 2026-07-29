<?php

namespace App\Platform\Media\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** P2/W04 - An asset was attached to another context's entity (scalar target reference). */
class MediaAttached
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly string $mediaId,
        public readonly string $attachableType,
        public readonly int $attachableId,
        public readonly int $actorId,
    ) {}
}
