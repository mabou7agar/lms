<?php

namespace App\Domains\Authoring\Filament\Resources\LessonResource\Pages;

use App\Domains\Authoring\Filament\Resources\LessonResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListLessons extends ListRecords
{
    protected static string $resource = LessonResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
