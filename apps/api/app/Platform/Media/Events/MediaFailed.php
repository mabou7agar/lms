<?php

namespace App\Platform\Media\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** P2/W04 - An asset failed upload or processing. */
class MediaFailed
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly string $mediaId,
        public readonly ?string $failureCode = null,
    ) {}
}
