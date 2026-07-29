<?php

namespace App\Platform\Media\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** P2/W04 - The provider confirmed bytes were received for an asset (verify/webhook). */
class MediaUploaded
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public readonly string $mediaId) {}
}
