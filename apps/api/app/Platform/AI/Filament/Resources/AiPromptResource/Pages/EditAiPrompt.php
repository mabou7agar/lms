<?php

namespace App\Platform\AI\Filament\Resources\AiPromptResource\Pages;

use App\Platform\AI\Filament\Resources\AiPromptResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditAiPrompt extends EditRecord
{
    protected static string $resource = AiPromptResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
