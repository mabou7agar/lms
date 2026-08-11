<?php

namespace App\Domains\Crm\Filament\Resources\ContactResource\Pages;

use App\Domains\Crm\Filament\Resources\ContactResource;
use Filament\Resources\Pages\EditRecord;

class EditContact extends EditRecord
{
    protected static string $resource = ContactResource::class;
}
