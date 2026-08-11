<?php

namespace App\Domains\Crm\Filament\Resources\TaskResource\Pages;

use App\Domains\Crm\Filament\Resources\TaskResource;
use Filament\Resources\Pages\ListRecords;

class ListTask extends ListRecords
{
    protected static string $resource = TaskResource::class;
}
