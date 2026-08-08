<?php

namespace App\Platform\Media\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Phase A / D6 - The deterministic image pipeline finished deriving variants for an asset.
 * Carries the asset public id and the ordered list of variant keys that were written.
 */
class MediaVariantsGenerated
{
    use Dispatchable;
    use SerializesModels;

    /** @param list<string> $variantKeys */
    public function __construct(
        public readonly string $mediaId,
        public readonly array $variantKeys,
    ) {}
}
