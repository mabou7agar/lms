<?php

namespace App\Contexts\Commerce\Filament\Resources;

use App\Contexts\Commerce\Actions\Subscription\CancelSubscriptionAction;
use App\Contexts\Commerce\Actions\Subscription\ChangePlanAction;
use App\Contexts\Commerce\Actions\Subscription\ReactivateSubscriptionAction;
use App\Contexts\Commerce\Enums\CommercePermission;
use App\Contexts\Commerce\Enums\SubscriptionStatus;
use App\Contexts\Commerce\Filament\Resources\SubscriptionResource\Pages;
use App\Contexts\Commerce\Models\Subscription;
use App\Contexts\Commerce\Models\SubscriptionPlan;
use App\Platform\Shared\Audit\AuditLog;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * Read-focused admin view of user subscriptions. The list and the View page are the only surfaces;
 * there is NO form editing of status, period dates, or captured-payment fields — those are owned by
 * the domain engine. Lifecycle operations (cancel-at-period-end, reactivate, change plan) are record
 * / page actions that DELEGATE to the existing Actions (which hold every state guard, the gateway
 * call and the audit trail); renewal is a system action and is only made VISIBLE here, never a manual
 * charge. Sensitive gateway references are redacted on display.
 *
 * Authorization: gated on the commerce subscriptions permission (finance_manager) for finance/support
 * separation.
 */
class SubscriptionResource extends Resource
{
    protected static ?string $model = Subscription::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-arrow-path-rounded-square';

    protected static string|\UnitEnum|null $navigationGroup = 'Commerce';

    protected static ?string $navigationLabel = 'Subscriptions';

    protected static ?string $recordRouteKeyName = 'public_id';

    /** Whether the signed-in operator may manage subscriptions (finance separation). */
    public static function canManage(): bool
    {
        $user = Auth::user();

        return $user !== null && $user->can(CommercePermission::ManageSubscriptions->value);
    }

    public static function canViewAny(): bool
    {
        return self::canManage();
    }

    public static function canView(Model $record): bool
    {
        return self::canManage();
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

    /**
     * The signed-in admin's IANA timezone (falling back to the platform default) so period/grace
     * instants render in the operator's local wall-clock. Guards a missing/non-IANA value.
     */
    public static function adminTimezone(): string
    {
        // Auth::check() rather than `?->`: larastan types Auth::user() as never-null, so a nullsafe
        // call reads as dead code to it, and dropping the guard entirely would fatal on the one
        // request that reaches a resource unauthenticated.
        $timezone = (Auth::check() ? Auth::user()->timezone : null) ?? config('shared.default_timezone', 'UTC');

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

        // The empty case already returned above, so the tail is always something.
        return str_repeat('•', 4).' '.substr($reference, -4);
    }

    public static function form(Schema $schema): Schema
    {
        // No editable form: status, period dates and captured-payment fields are engine-owned.
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            // Eager-load the display relations so the list never issues a per-row query for the
            // user email / plan name columns (N+1 guard).
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['user', 'plan']))
            ->columns([
                TextColumn::make('public_id')->label('Subscription')->searchable(),
                TextColumn::make('user.email')->label('User')->toggleable(),
                TextColumn::make('plan.name')->label('Plan')->toggleable(),
                TextColumn::make('status')->badge()
                    ->formatStateUsing(fn ($state) => $state instanceof SubscriptionStatus ? ucfirst($state->value) : $state)
                    ->color(fn ($state) => $state === SubscriptionStatus::Active || $state === SubscriptionStatus::Trialing ? 'success' : 'gray'),
                IconColumn::make('cancel_at_period_end')->boolean()->label('Cancel pending')->toggleable(),
                TextColumn::make('current_period_end')->dateTime(timezone: self::adminTimezone())->label('Period ends')->sortable(),
            ])->defaultSort('id', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(SubscriptionStatus::cases())->mapWithKeys(fn (SubscriptionStatus $s) => [$s->value => ucfirst($s->value)])->all()),
            ])
            ->recordActions([
                ViewAction::make(),
                self::cancelAction(),
                self::cancelImmediatelyAction(),
                self::reactivateAction(),
                self::changePlanAction(),
            ]);
    }

    /** @return array<int, Action> */
    public static function lifecycleActions(): array
    {
        return [
            self::cancelAction(),
            self::cancelImmediatelyAction(),
            self::reactivateAction(),
            self::changePlanAction(),
        ];
    }

    /** Schedule cancellation at period end — delegates to CancelSubscriptionAction. */
    public static function cancelAction(): Action
    {
        return Action::make('cancel')
            ->label('Cancel at period end')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription('Schedule cancellation at the end of the current paid period? Access continues until then; the engine finalises it when the period rolls over.')
            ->visible(fn (Subscription $record): bool => self::canManage() && ! self::isTerminal($record))
            ->action(function (Subscription $record): void {
                try {
                    app(CancelSubscriptionAction::class)->execute($record, atPeriodEnd: true);
                    Notification::make()->title('Cancellation scheduled')->success()->send();
                } catch (Throwable $e) {
                    Notification::make()->title('Cancel failed')->body($e->getMessage())->danger()->send();
                }
            });
    }

    /**
     * Cancel immediately — delegates to CancelSubscriptionAction with atPeriodEnd:false, terminating
     * access now rather than at the period boundary. This is a distinct, explicitly-confirmed action
     * from the (default) cancel-at-period-end button; it introduces no raw status editing — the domain
     * action holds every state guard, dispatches SubscriptionCanceled(immediate) and writes the audit
     * entry.
     */
    public static function cancelImmediatelyAction(): Action
    {
        return Action::make('cancelImmediately')
            ->label('Cancel immediately')
            ->icon('heroicon-o-no-symbol')
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription('Cancel this subscription immediately? Access is revoked now — the remainder of the paid period is forfeited. This cannot be undone.')
            ->visible(fn (Subscription $record): bool => self::canManage() && ! self::isTerminal($record))
            ->action(function (Subscription $record): void {
                try {
                    app(CancelSubscriptionAction::class)->execute($record, atPeriodEnd: false);
                    Notification::make()->title('Subscription canceled')->success()->send();
                } catch (Throwable $e) {
                    Notification::make()->title('Cancel failed')->body($e->getMessage())->danger()->send();
                }
            });
    }

    /** Reactivate a canceled/pending-cancel subscription — delegates to ReactivateSubscriptionAction. */
    public static function reactivateAction(): Action
    {
        return Action::make('reactivate')
            ->label('Reactivate')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('success')
            ->requiresConfirmation()
            ->modalDescription('Reactivate this subscription while its paid-through period is still open? No charge is taken.')
            ->visible(fn (Subscription $record): bool => self::canManage()
                && ($record->cancelAtPeriodEnd() || $record->statusEnum() === SubscriptionStatus::Canceled))
            ->action(function (Subscription $record): void {
                try {
                    app(ReactivateSubscriptionAction::class)->execute($record);
                    Notification::make()->title('Subscription reactivated')->success()->send();
                } catch (Throwable $e) {
                    Notification::make()->title('Reactivate failed')->body($e->getMessage())->danger()->send();
                }
            });
    }

    /** Change the plan (upgrade now / downgrade at period end) — delegates to ChangePlanAction. */
    public static function changePlanAction(): Action
    {
        return Action::make('changePlan')
            ->label('Change plan')
            ->icon('heroicon-o-arrows-right-left')
            ->color('primary')
            ->requiresConfirmation()
            ->modalDescription('Move this subscription to another plan. Upgrades charge the prorated difference now; downgrades apply at the next period boundary.')
            ->schema([
                Select::make('plan_id')->label('Target plan')->required()
                    ->options(fn (Subscription $record): array => SubscriptionPlan::query()
                        ->where('is_active', true)
                        ->whereKeyNot($record->planId())
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all()),
                TextInput::make('currency')->label('Currency (optional)')->maxLength(3)
                    ->helperText('Leave blank to keep the subscription currency.'),
            ])
            ->visible(fn (Subscription $record): bool => self::canManage() && ! self::isTerminal($record))
            ->action(function (array $data, Subscription $record): void {
                $plan = SubscriptionPlan::query()->find($data['plan_id']);

                if (! $plan instanceof SubscriptionPlan) {
                    Notification::make()->title('Change failed')->body('Target plan not found.')->danger()->send();

                    return;
                }

                $currency = isset($data['currency']) && $data['currency'] !== '' ? (string) $data['currency'] : null;

                try {
                    app(ChangePlanAction::class)->execute($record, $plan, $currency);
                    Notification::make()->title('Plan change applied')->success()->send();
                } catch (Throwable $e) {
                    Notification::make()->title('Change failed')->body($e->getMessage())->danger()->send();
                }
            });
    }

    /** Terminal subscriptions (canceled/expired) accept no further lifecycle transition. */
    private static function isTerminal(Subscription $subscription): bool
    {
        $status = $subscription->statusEnum();

        return $status === SubscriptionStatus::Canceled || $status === SubscriptionStatus::Expired;
    }

    /**
     * The append-only audit history for one subscription — every privileged lifecycle transition
     * (renewed, renewal_failed, cancel_scheduled, canceled, …) the domain Actions recorded, oldest
     * first. Mirrors RefundResource::auditTrail so the history renders identically across surfaces.
     *
     * @return array<int, string>
     */
    public static function auditTrail(Subscription $record): array
    {
        $entries = AuditLog::query()
            ->where('subject_type', $record->getMorphClass())
            ->where('subject_id', $record->getKey())
            ->orderBy('id')
            ->get(['action', 'created_at'])
            ->map(fn (AuditLog $log): string => sprintf(
                '%s — %s',
                (string) $log->getAttribute('action'),
                optional($log->getAttribute('created_at'))->toDateTimeString() ?? '',
            ))
            ->all();

        return $entries === [] ? ['No audit entries.'] : $entries;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSubscriptions::route('/'),
            'view' => Pages\ViewSubscription::route('/{record}'),
        ];
    }
}
