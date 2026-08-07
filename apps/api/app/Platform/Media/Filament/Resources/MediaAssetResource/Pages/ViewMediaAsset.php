<?php

namespace App\Platform\Media\Filament\Resources\MediaAssetResource\Pages;

use App\Platform\Media\Filament\Resources\MediaAssetResource;
use Filament\Resources\Pages\ViewRecord;

/**
 * Read-only detail: metadata, processing/failure/retry state, usage references, a signed preview
 * URL and a redacted provider reference. All mutations (only a safe delete) live on the resource's
 * record actions and delegate to MediaDeletionService.
 */
class ViewMediaAsset extends ViewRecord
{
    protected static string $resource = MediaAssetResource::class;
}
