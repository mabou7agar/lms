<?php

namespace App\Platform\Media\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * P2/W04 - A direct-upload slot was issued for a new asset. Scalar-only payload (no Eloquent model).
 */
class MediaUploadCreated
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly string $mediaId,
        public readonly int $actorId,
        public readonly string $type,
        public readonly string $provider,
    ) {}
}
