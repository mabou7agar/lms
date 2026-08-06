<?php

namespace App\Domains\Assessment\Filament\Resources\AssessmentResource\Pages;

use App\Domains\Assessment\Filament\Resources\AssessmentResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateAssessment extends CreateRecord
{
    protected static string $resource = AssessmentResource::class;

    /**
     * Stamp the authoring admin. Status/version are left to the model defaults (draft, v1) — a new
     * assessment is never born published, mirroring CreateAssessmentAction.
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
