<?php

namespace App\Contexts\Commerce\Filament\Resources\OrderResource\Pages;

use App\Contexts\Commerce\Filament\Resources\OrderResource;
use Filament\Resources\Pages\ViewRecord;

/**
 * Read-only order detail. Its purpose is the payment-attempts trail (S1): the checkout + dunning
 * attempts recorded against this order, with masked provider references and sanitized failure
 * reasons. No mutation controls live here.
 */
class ViewOrder extends ViewRecord
{
    protected static string $resource = OrderResource::class;
}
