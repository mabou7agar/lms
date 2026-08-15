<?php

namespace App\Domains\Certification\Filament\Resources\BadgeResource\Pages;

use App\Domains\Certification\Filament\Resources\BadgeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListBadge extends ListRecords
{
    protected static string $resource = BadgeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
