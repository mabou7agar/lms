<?php

namespace App\Platform\Media\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** P2/W04 - An asset was deleted (remote asset removed, row soft-deleted). */
class MediaDeleted
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly string $mediaId,
        public readonly int $actorId,
    ) {}
}
