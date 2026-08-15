<?php

namespace App\Domains\Certification\Filament\Resources\CertificateTemplateResource\Pages;

use App\Domains\Certification\Filament\Resources\CertificateTemplateResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCertificateTemplate extends ListRecords
{
    protected static string $resource = CertificateTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
