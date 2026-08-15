<?php

namespace App\Domains\Live\Filament\Resources\LiveCourseResource\Pages;

use App\Domains\Live\Filament\Resources\LiveCourseResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListLiveCourse extends ListRecords
{
    protected static string $resource = LiveCourseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
