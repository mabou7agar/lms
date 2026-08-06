<?php

namespace App\Contexts\Commerce\Filament\Resources\SubscriptionPlanResource\Pages;

use App\Contexts\Commerce\Filament\Resources\SubscriptionPlanResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditSubscriptionPlan extends EditRecord
{
    protected static string $resource = SubscriptionPlanResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
