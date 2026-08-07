<?php

namespace App\Contexts\Commerce\Filament\Resources\RefundResource\Pages;

use App\Contexts\Commerce\Filament\Resources\RefundResource;
use Filament\Resources\Pages\ViewRecord;

class ViewRefund extends ViewRecord
{
    protected static string $resource = RefundResource::class;

    /**
     * The only header action is the R4 retry, and it renders solely for a FAILED refund (its own
     * visible() guard). It delegates wholesale to the domain refund engine — see
     * RefundResource::retryAction().
     */
    protected function getHeaderActions(): array
    {
        return [
            RefundResource::retryAction(),
        ];
    }
}
