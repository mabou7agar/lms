<?php

namespace App\Domains\Assessment\Filament\Resources\AssessmentResource\Pages;

use App\Domains\Assessment\Filament\Resources\AssessmentResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditAssessment extends EditRecord
{
    protected static string $resource = AssessmentResource::class;

    /** @return array<int, \Filament\Actions\Action> */
    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
