<?php

namespace App\Domains\Crm\Filament\Resources\ContactResource\Pages;

use App\Domains\Crm\Filament\Resources\ContactResource;
use Filament\Resources\Pages\ListRecords;

class ListContact extends ListRecords
{
    protected static string $resource = ContactResource::class;
}
