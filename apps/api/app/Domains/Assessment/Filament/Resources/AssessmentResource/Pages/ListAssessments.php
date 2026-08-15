<?php

namespace App\Domains\Assessment\Filament\Resources\AssessmentResource\Pages;

use App\Domains\Assessment\Filament\Resources\AssessmentResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAssessments extends ListRecords
{
    protected static string $resource = AssessmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
