<?php

namespace App\Domains\Assessment\Filament\Resources\AssessmentResource\Pages;

use App\Domains\Assessment\Filament\Resources\AssessmentResource;
use Filament\Resources\Pages\ListRecords;

class ListAssessments extends ListRecords
{
    protected static string $resource = AssessmentResource::class;
}
