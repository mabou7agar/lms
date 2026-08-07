<?php

namespace App\Platform\Media\Filament\Resources\MediaFolderResource\Pages;

use App\Platform\Identity\Contracts\Actor;
use App\Platform\Media\Filament\Resources\MediaFolderResource;
use App\Platform\Media\Models\MediaFolder;
use App\Platform\Media\Services\MediaFolderService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class CreateMediaFolder extends CreateRecord
{
    protected static string $resource = MediaFolderResource::class;

    /**
     * Delegate creation to MediaFolderService so created_by/owner scoping is set consistently rather
     * than mass-assigned from the form.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $user = Auth::user();
        $actorId = $user instanceof Actor ? $user->actorId() : 0;

        $parent = ! empty($data['parent_id'])
            ? MediaFolder::query()->find($data['parent_id'])
            : null;

        return app(MediaFolderService::class)->create((string) $data['name'], $actorId, $parent);
    }
}
