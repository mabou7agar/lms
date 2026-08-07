<?php

namespace App\Contexts\Commerce\Filament\Resources;

use App\Contexts\Commerce\Actions\Payment\RefundOrderAction;
use App\Contexts\Commerce\Enums\OrderStatus;
use App\Contexts\Commerce\Enums\PaymentAttemptStatus;
use App\Contexts\Commerce\Filament\Resources\OrderResource\Pages;
use App\Contexts\Commerce\Models\Order;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Throwable;

class OrderResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-receipt-percent';

    protected static string|\UnitEnum|null $navigationGroup = 'Commerce';

    protected static ?string $recordRouteKeyName = 'public_id';

    /**
     * The signed-in admin's IANA timezone (falling back to the platform default) so attempt instants
     * render in the operator's local wall-clock. Guards a missing/non-IANA value.
     */
    public static function adminTimezone(): string
    {
        $timezone = Auth::user()?->timezone ?? config('shared.default_timezone', 'UTC');

        return is_string($timezone) && in_array($timezone, timezone_identifiers_list(), true)
            ? $timezone
            : 'UTC';
    }

    /**
     * Mask a gateway reference so raw provider/token data is never rendered: only the last four
     * characters survive behind bullets. Empty/null becomes an em dash.
     */
    public static function redact(?string $reference): string
    {
        if ($reference === null || $reference === '') {
            return '—';
        }

        return '••••'.substr($reference, -4);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function infolist(Schema $schema): Schema
    {
        $timezone = self::adminTimezone();

        return $schema->components([
            Section::make('Order')->columns(3)->schema([
                TextEntry::make('public_id')->label('Order'),
                TextEntry::make('user.email')->label('Customer')->placeholder('—'),
                TextEntry::make('status')->badge(),
                TextEntry::make('total_minor')->label('Total (minor units)')->numeric(),
                TextEntry::make('currency'),
                TextEntry::make('paid_at')->dateTime(timezone: $timezone)->placeholder('—'),
            ]),
            // S1 — read-only payment-attempts trail (checkout + dunning). No mutation controls: rows
            // are engine-written by PaymentRecoveryService. Gateway references are masked; the failure
            // indicator is the sanitized error_code, never a raw gateway payload.
            Section::make('Payment attempts')->schema([
                RepeatableEntry::make('paymentAttempts')->label('Attempts (oldest first)')
                    ->schema([
                        TextEntry::make('attempt_no')->label('#'),
                        TextEntry::make('status')->badge()
                            ->formatStateUsing(fn ($state) => $state instanceof PaymentAttemptStatus ? ucfirst($state->value) : $state)
                            ->color(fn ($state) => $state === PaymentAttemptStatus::Succeeded ? 'success'
                                : ($state === PaymentAttemptStatus::Failed ? 'danger' : 'gray')),
                        TextEntry::make('amount_minor')->label('Amount (minor)')->numeric(),
                        TextEntry::make('currency'),
                        TextEntry::make('provider'),
                        TextEntry::make('provider_reference')->label('Provider reference (masked)')
                            ->formatStateUsing(fn ($state) => self::redact(is_string($state) ? $state : null)),
                        TextEntry::make('error_code')->label('Failure reason')->placeholder('—'),
                        TextEntry::make('created_at')->label('Started')->dateTime(timezone: $timezone),
                        TextEntry::make('updated_at')->label('Updated')->dateTime(timezone: $timezone),
                    ])
                    ->columns(3),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            // Eager-load the purchasing user so the customer column never issues a per-row query.
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('user'))
            ->columns([
            TextColumn::make('public_id')->label('Order')->searchable(),
            TextColumn::make('user.email')->label('User')->toggleable(),
            TextColumn::make('status')->badge(),
            TextColumn::make('total_minor')->label('Total (minor)')->sortable(),
            TextColumn::make('currency'),
            TextColumn::make('paid_at')->dateTime()->toggleable(),
        ])->defaultSort('id', 'desc')
            ->recordActions([
                ViewAction::make(),
                // Orchestration only: delegates to the domain RefundOrderAction (locking,
                // idempotency, gateway call, and audit all live in the action).
                Action::make('refund')
                    ->label('Refund')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('Refund this paid order and revoke the related enrollment? This cannot be undone.')
                    ->visible(fn (Order $record): bool => $record->status === OrderStatus::Paid)
                    ->action(function (Order $record): void {
                        try {
                            app(RefundOrderAction::class)->execute($record);
                            Notification::make()->title('Order refunded')->success()->send();
                        } catch (Throwable $e) {
                            Notification::make()->title('Refund failed')->body($e->getMessage())->danger()->send();
                        }
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOrder::route('/'),
            'view' => Pages\ViewOrder::route('/{record}'),
        ];
    }
}
