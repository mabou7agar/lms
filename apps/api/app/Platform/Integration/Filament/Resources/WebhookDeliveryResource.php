<?php

namespace App\Platform\Integration\Filament\Resources;

use App\Platform\Integration\Enums\DeliveryStatus;
use App\Platform\Integration\Filament\Resources\WebhookDeliveryResource\Pages;
use App\Platform\Integration\Models\WebhookDelivery;
use App\Platform\Integration\Services\WebhookEndpointService;
use App\Platform\Shared\Tenancy\TenantScope;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Admin read view over outbound delivery attempts (status, response, error) with a REPLAY action that
 * re-queues a fresh delivery of the same payload. Read-only otherwise; cross-tenant for platform admins.
 */
class WebhookDeliveryResource extends Resource
{
    protected static ?string $model = WebhookDelivery::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-arrow-up-right';

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?string $navigationLabel = 'Webhook Deliveries';

    protected static ?string $recordRouteKeyName = 'public_id';

    public static function canViewAny(): bool
    {
        return self::userCanManage();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function userCanManage(): bool
    {
        $user = auth()->user() ?? Filament::auth()->user();

        if ($user === null || ! method_exists($user, 'hasRole')) {
            return false;
        }

        return $user->hasRole('super_admin') || $user->hasRole('admin');
    }

    /** @return Builder<WebhookDelivery> */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScope(TenantScope::class);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->dateTime()->sortable()->label('When'),
                TextColumn::make('event_type')->badge()->searchable(),
                TextColumn::make('webhook_endpoint_id')->label('Endpoint')->toggleable(),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('attempts')->sortable(),
                TextColumn::make('response_status')->label('HTTP')->toggleable(),
                TextColumn::make('response_ms')->label('ms')->toggleable(),
                TextColumn::make('error')->limit(40)->toggleable(),
                TextColumn::make('next_retry_at')->dateTime()->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(collect(DeliveryStatus::cases())
                    ->mapWithKeys(fn (DeliveryStatus $s): array => [$s->value => ucfirst($s->value)])
                    ->all()),
            ])
            ->recordActions([
                Action::make('replay')
                    ->label('Replay')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->visible(fn (): bool => self::userCanManage())
                    ->action(function (WebhookDelivery $record): void {
                        app(WebhookEndpointService::class)->replay($record);
                        Notification::make()->title('Delivery re-queued')->success()->send();
                    }),
            ])
            ->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListWebhookDeliveries::route('/')];
    }
}
