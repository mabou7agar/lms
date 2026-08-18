<?php

namespace App\Platform\Media\Filament\Resources\MediaFolderResource\Pages;

use App\Platform\Media\Filament\Resources\MediaFolderResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListMediaFolders extends ListRecords
{
    protected static string $resource = MediaFolderResource::class;

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->visible(fn (): bool => MediaFolderResource::canCreate()),
        ];
    }
}
