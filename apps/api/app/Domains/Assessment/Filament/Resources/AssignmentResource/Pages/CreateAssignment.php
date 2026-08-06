<?php

namespace App\Domains\Assessment\Filament\Resources\AssignmentResource\Pages;

use App\Domains\Assessment\Filament\Resources\AssignmentResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateAssignment extends CreateRecord
{
    protected static string $resource = AssignmentResource::class;

    /**
     * Stamp the authoring admin. The publish_state defaults to draft (model/DB default) — an
     * assignment is never born published, mirroring AssignmentService::createAssignment.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = Auth::id();

        return $data;
    }
}
