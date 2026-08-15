<?php

namespace App\Domains\Catalog\Filament\Resources\CourseResource\Pages;

use App\Domains\Catalog\Filament\Resources\CourseResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCourses extends ListRecords
{
    protected static string $resource = CourseResource::class;

    /** Header "New course" button — the list page's entry point to the create form. */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
