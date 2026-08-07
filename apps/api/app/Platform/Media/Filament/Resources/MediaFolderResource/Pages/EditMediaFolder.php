<?php

namespace App\Platform\Media\Filament\Resources\MediaFolderResource\Pages;

use App\Platform\Identity\Contracts\Actor;
use App\Platform\Media\Exceptions\MediaValidationException;
use App\Platform\Media\Filament\Resources\MediaFolderResource;
use App\Platform\Media\Models\MediaFolder;
use App\Platform\Media\Services\MediaFolderService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class EditMediaFolder extends EditRecord
{
    protected static string $resource = MediaFolderResource::class;

    /**
     * Delegate rename + move to MediaFolderService so the cycle guard applies (a folder can never be
     * moved inside itself or a descendant). A rejected move halts the save with a clear notice.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var MediaFolder $record */
        $user = Auth::user();
        $actorId = $user instanceof Actor ? $user->actorId() : 0;
        $service = app(MediaFolderService::class);

        $service->rename($record, (string) $data['name'], $actorId);

        $newParentId = $data['parent_id'] ?? null;

        if ((int) $record->parent_id !== (int) $newParentId) {
            $parent = ! empty($newParentId) ? MediaFolder::query()->find($newParentId) : null;

            try {
                $service->move($record, $parent, $actorId);
            } catch (MediaValidationException $e) {
                Notification::make()->title('Cannot move folder')->body($e->getMessage())->danger()->send();

                throw new Halt;
            }
        }

        return $record->refresh();
    }
}
