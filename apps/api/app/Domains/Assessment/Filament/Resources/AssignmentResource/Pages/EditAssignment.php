<?php

namespace App\Domains\Assessment\Filament\Resources\AssignmentResource\Pages;

use App\Domains\Assessment\Filament\Resources\AssignmentResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditAssignment extends EditRecord
{
    protected static string $resource = AssignmentResource::class;

    /** @return array<int, \Filament\Actions\Action> */
    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
