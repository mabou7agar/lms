<?php

namespace App\Domains\Catalog\Filament\Resources\CourseResource\Pages;

use App\Domains\Catalog\Filament\Resources\CourseResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;

class EditCourse extends EditRecord
{
    protected static string $resource = CourseResource::class;

    /**
     * The guarded lifecycle actions (Submit for review, Approve, Schedule…, Publish, Unpublish,
     * Archive, Restore). Each is self-guarding: visible only when the operator may manage courses and
     * the state machine permits the move from the current status.
     *
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [...CourseResource::lifecycleActions(), CourseResource::duplicateAction()];
    }
}
