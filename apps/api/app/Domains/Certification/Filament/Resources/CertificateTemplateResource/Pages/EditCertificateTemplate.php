<?php

namespace App\Domains\Certification\Filament\Resources\CertificateTemplateResource\Pages;

use App\Domains\Certification\Filament\Resources\CertificateTemplateResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;

class EditCertificateTemplate extends EditRecord
{
    protected static string $resource = CertificateTemplateResource::class;

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [
            CertificateTemplateResource::previewAction(),
        ];
    }
}
