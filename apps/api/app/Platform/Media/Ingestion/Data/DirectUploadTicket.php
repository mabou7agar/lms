<?php

namespace App\Platform\Media\Ingestion\Data;

use App\Platform\Media\Models\MediaAsset;
use App\Platform\Shared\Media\Data\DirectUploadInstructions;

/**
 * P2/W04 - Internal result of MediaUploadService::createDirectUpload: the persisted asset, the
 * opaque provider upload instructions the browser needs, and the single-use finalize token the
 * client returns to confirm the upload. Never crosses a context boundary (Media-internal only) and
 * is shaped for the client by DirectUploadTicketResource.
 */
final readonly class DirectUploadTicket
{
    public function __construct(
        public MediaAsset $asset,
        public DirectUploadInstructions $instructions,
        public string $uploadToken,
    ) {}
}
