<?php

namespace App\Platform\Blog\Filament\Resources\BlogPostResource\Pages;

use App\Platform\Blog\Filament\Resources\BlogPostResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateBlogPost extends CreateRecord
{
    protected static string $resource = BlogPostResource::class;

    /** Stamp the authoring admin on create. */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['author_id'] ??= Auth::id();

        return $data;
    }
}
