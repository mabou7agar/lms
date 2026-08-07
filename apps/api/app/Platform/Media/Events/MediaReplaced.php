<?php

namespace App\Platform\Media\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Phase 8 / D2 - An asset was replaced: every usage of $replacedMediaId was repointed onto
 * $mediaId (a freshly ingested version) and the original was retired.
 */
class MediaReplaced
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly string $mediaId,
        public readonly string $replacedMediaId,
        public readonly int $actorId,
    ) {}
}
