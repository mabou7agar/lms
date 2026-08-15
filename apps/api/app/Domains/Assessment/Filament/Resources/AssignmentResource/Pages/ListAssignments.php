<?php

namespace App\Domains\Assessment\Filament\Resources\AssignmentResource\Pages;

use App\Domains\Assessment\Filament\Resources\AssignmentResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAssignments extends ListRecords
{
    protected static string $resource = AssignmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
