<?php

namespace App\Contexts\Learning\Filament\Resources\CourseCompletionPolicyResource\Pages;

use App\Contexts\Learning\Filament\Resources\CourseCompletionPolicyResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCourseCompletionPolicies extends ListRecords
{
    protected static string $resource = CourseCompletionPolicyResource::class;

    /** @return array<int, \Filament\Actions\Action> */
    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
