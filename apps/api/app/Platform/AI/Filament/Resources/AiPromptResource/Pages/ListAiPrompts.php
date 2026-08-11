<?php

namespace App\Platform\AI\Filament\Resources\AiPromptResource\Pages;

use App\Platform\AI\Filament\Resources\AiPromptResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAiPrompts extends ListRecords
{
    protected static string $resource = AiPromptResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
