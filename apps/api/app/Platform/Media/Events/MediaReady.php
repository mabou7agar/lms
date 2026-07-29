<?php

namespace App\Platform\Media\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** P2/W04 - An asset finished processing and is now playable. */
class MediaReady
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public readonly string $mediaId) {}
}
