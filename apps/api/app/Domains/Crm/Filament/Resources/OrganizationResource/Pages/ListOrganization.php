<?php

namespace App\Domains\Crm\Filament\Resources\OrganizationResource\Pages;

use App\Domains\Crm\Filament\Resources\OrganizationResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListOrganization extends ListRecords
{
    protected static string $resource = OrganizationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
