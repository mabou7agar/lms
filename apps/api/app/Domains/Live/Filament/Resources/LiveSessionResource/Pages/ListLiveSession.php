<?php

namespace App\Domains\Live\Filament\Resources\LiveSessionResource\Pages;

use App\Domains\Live\Filament\Resources\LiveSessionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListLiveSession extends ListRecords
{
    protected static string $resource = LiveSessionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
