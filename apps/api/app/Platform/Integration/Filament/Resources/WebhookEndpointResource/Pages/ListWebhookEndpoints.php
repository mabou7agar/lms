<?php

namespace App\Platform\Integration\Filament\Resources\WebhookEndpointResource\Pages;

use App\Platform\Integration\Filament\Resources\WebhookEndpointResource;
use Filament\Resources\Pages\ListRecords;

class ListWebhookEndpoints extends ListRecords
{
    protected static string $resource = WebhookEndpointResource::class;
}
