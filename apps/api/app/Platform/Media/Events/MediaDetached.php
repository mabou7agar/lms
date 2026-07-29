<?php

namespace App\Platform\Media\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** P2/W04 - An asset was detached from another context's entity. */
class MediaDetached
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
