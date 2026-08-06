<?php

namespace App\Contexts\Commerce\Filament\Resources\SubscriptionResource\Pages;

use App\Contexts\Commerce\Enums\SubscriptionChangeType;
use App\Contexts\Commerce\Enums\SubscriptionStatus;
use App\Contexts\Commerce\Filament\Resources\SubscriptionResource;
use App\Contexts\Commerce\Models\Subscription;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Read-only detail of one subscription: status + entitlement, the (tz-aware) billing window, the
 * redacted charge/provider data, and the append-only lifecycle timeline. Lifecycle operations are
 * exposed as header actions that delegate to the domain Actions; nothing here mutates state directly.
 */
class ViewSubscription extends ViewRecord
{
    protected static string $resource = SubscriptionResource::class;

    protected function getHeaderActions(): array
    {
        return SubscriptionResource::lifecycleActions();
    }

    public function infolist(Schema $schema): Schema
    {
        $timezone = SubscriptionResource::adminTimezone();

        return $schema->components([
            Section::make('Status & entitlement')->columns(3)->schema([
                TextEntry::make('status')->badge()
                    ->formatStateUsing(fn ($state) => $state instanceof SubscriptionStatus ? ucfirst($state->value) : $state)
                    ->color(fn ($state) => $state === SubscriptionStatus::Active || $state === SubscriptionStatus::Trialing ? 'success' : 'gray'),
                TextEntry::make('entitlement')->label('Grants access now')
                    ->state(fn (Subscription $record): string => $record->isActiveNow() ? 'Yes' : 'No')
                    ->badge()->color(fn (Subscription $record): string => $record->isActiveNow() ? 'success' : 'danger'),
                IconEntry::make('cancel_at_period_end')->label('Cancel at period end')->boolean(),
            ]),
            Section::make('Billing window')->columns(3)->schema([
                TextEntry::make('current_period_start')->label('Period start')->dateTime(timezone: $timezone)->placeholder('—'),
                TextEntry::make('current_period_end')->label('Period end')->dateTime(timezone: $timezone)->placeholder('—'),
                TextEntry::make('trial_ends_at')->label('Trial ends')->dateTime(timezone: $timezone)->placeholder('—'),
                TextEntry::make('grace_ends_at')->label('Grace ends')->dateTime(timezone: $timezone)->placeholder('—'),
                TextEntry::make('canceled_at')->label('Canceled at')->dateTime(timezone: $timezone)->placeholder('—'),
                TextEntry::make('plan.name')->label('Plan')->placeholder('—'),
            ]),
            Section::make('Charges (sensitive data redacted)')->columns(3)->schema([
                TextEntry::make('provider')->label('Provider')->placeholder('—'),
                TextEntry::make('provider_reference')->label('Provider reference')
                    ->formatStateUsing(fn ($state) => SubscriptionResource::redact(is_string($state) ? $state : null)),
                TextEntry::make('currency')->label('Currency'),
                TextEntry::make('amount_minor')->label('Recurring amount (minor units)'),
            ]),
            Section::make('Lifecycle timeline')->schema([
                RepeatableEntry::make('changes')->label('Transitions')
                    ->schema([
                        TextEntry::make('type')->badge()
                            ->formatStateUsing(fn ($state) => $state instanceof SubscriptionChangeType ? ucfirst(str_replace('_', ' ', $state->value)) : $state),
                        TextEntry::make('amount_minor')->label('Amount (minor)'),
                        TextEntry::make('note')->placeholder('—'),
                        TextEntry::make('created_at')->label('When')->dateTime(timezone: $timezone),
                    ])
                    ->columns(4),
            ]),
        ]);
    }
}
