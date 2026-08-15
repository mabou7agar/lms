<?php

namespace App\Platform\Blog\Filament\Resources\BlogCategoryResource\Pages;

use App\Platform\Blog\Filament\Resources\BlogCategoryResource;
use Filament\Resources\Pages\CreateRecord;

class CreateBlogCategory extends CreateRecord
{
    protected static string $resource = BlogCategoryResource::class;
}
