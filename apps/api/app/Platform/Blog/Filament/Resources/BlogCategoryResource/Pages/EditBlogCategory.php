<?php

namespace App\Platform\Blog\Filament\Resources\BlogCategoryResource\Pages;

use App\Platform\Blog\Filament\Resources\BlogCategoryResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditBlogCategory extends EditRecord
{
    protected static string $resource = BlogCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
