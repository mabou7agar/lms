<?php

namespace App\Contexts\Learning\Filament\Resources\CourseCompletionPolicyResource\Pages;

use App\Contexts\Learning\Filament\Resources\CourseCompletionPolicyResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCourseCompletionPolicy extends EditRecord
{
    protected static string $resource = CourseCompletionPolicyResource::class;

    /** @return array<int, \Filament\Actions\Action> */
    protected function getHeaderActions(): array
    {
        // Deleting the row reverts the course to the platform default completion behaviour.
        return [DeleteAction::make()];
    }
}
