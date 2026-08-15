<?php

namespace App\Contexts\Commerce\Filament\Resources\ContractTemplateResource\Pages;

use App\Contexts\Commerce\Filament\Resources\ContractTemplateResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListContractTemplate extends ListRecords
{
    protected static string $resource = ContractTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
