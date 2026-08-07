<?php

namespace App\Platform\Media\Filament\Resources\MediaAssetResource\Pages;

use App\Platform\Media\Filament\Resources\MediaAssetResource;
use Filament\Resources\Pages\ListRecords;

/**
 * Media library list. There is deliberately NO "create" header action: assets are born only through
 * the existing direct-upload session + webhook ingestion pipeline, never authored in the panel.
 */
class ListMediaAssets extends ListRecords
{
    protected static string $resource = MediaAssetResource::class;
}
