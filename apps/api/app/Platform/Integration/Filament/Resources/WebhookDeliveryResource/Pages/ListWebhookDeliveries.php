<?php

namespace App\Platform\Integration\Filament\Resources\WebhookDeliveryResource\Pages;

use App\Platform\Integration\Filament\Resources\WebhookDeliveryResource;
use Filament\Resources\Pages\ListRecords;

class ListWebhookDeliveries extends ListRecords
{
    protected static string $resource = WebhookDeliveryResource::class;
}
