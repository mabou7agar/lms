<?php

namespace App\Platform\Integration\Filament\Resources;

use App\Platform\Integration\Filament\Resources\WebhookEndpointResource\Pages;
use App\Platform\Integration\Models\WebhookEndpoint;
use App\Platform\Integration\Services\WebhookEndpointService;
use App\Platform\Shared\Tenancy\TenantScope;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Admin (company-operated) view over every tenant's outbound webhook endpoints. Read + enable/disable
 * only — creation/rotation is a tenant self-service concern via the API, and the secret is never shown.
 * The tenant global scope is dropped so a platform admin sees endpoints across all organizations.
 */
class WebhookEndpointResource extends Resource
{
    protected static ?string $model = WebhookEndpoint::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-bolt';

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?string $navigationLabel = 'Webhook Endpoints';

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

    /** @return Builder<WebhookEndpoint> */
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
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('url')->limit(48)->toggleable(),
                TextColumn::make('organization_id')->label('Org')->toggleable(),
                TextColumn::make('event_types')->badge()->label('Events'),
                IconColumn::make('active')->boolean()->sortable(),
                TextColumn::make('consecutive_failures')->label('Fails')->sortable(),
                TextColumn::make('disabled_at')->dateTime()->toggleable(),
                TextColumn::make('created_at')->dateTime()->sortable()->toggleable(),
            ])
            ->recordActions([
                Action::make('disable')
                    ->label('Disable')
                    ->icon('heroicon-o-pause')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (WebhookEndpoint $record): bool => self::userCanManage() && $record->active)
                    ->action(function (WebhookEndpoint $record): void {
                        app(WebhookEndpointService::class)->setActive($record, false);
                        Notification::make()->title('Endpoint disabled')->success()->send();
                    }),
                Action::make('enable')
                    ->label('Enable')
                    ->icon('heroicon-o-play')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (WebhookEndpoint $record): bool => self::userCanManage() && ! $record->active)
                    ->action(function (WebhookEndpoint $record): void {
                        app(WebhookEndpointService::class)->setActive($record, true);
                        Notification::make()->title('Endpoint enabled')->success()->send();
                    }),
            ])
            ->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListWebhookEndpoints::route('/')];
    }
}
