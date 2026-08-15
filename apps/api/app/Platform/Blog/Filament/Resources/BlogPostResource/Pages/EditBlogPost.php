<?php

namespace App\Platform\Blog\Filament\Resources\BlogPostResource\Pages;

use App\Platform\Blog\Actions\UpdateBlogPostAction;
use App\Platform\Blog\Enums\PostStatus;
use App\Platform\Blog\Filament\Resources\BlogPostResource;
use App\Platform\Blog\Models\BlogPost;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditBlogPost extends EditRecord
{
    protected static string $resource = BlogPostResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('publish')
                ->label('Publish')
                ->icon('heroicon-o-rocket-launch')
                ->color('success')
                ->visible(fn (BlogPost $record): bool => $record->status !== PostStatus::Published)
                ->requiresConfirmation()
                ->action(function (BlogPost $record): void {
                    app(UpdateBlogPostAction::class)->publish($record);
                    Notification::make()->title('Post published')->success()->send();
                    $this->fillForm();
                }),
            DeleteAction::make(),
        ];
    }
}
