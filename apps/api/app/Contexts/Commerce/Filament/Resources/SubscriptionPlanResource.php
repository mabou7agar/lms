<?php

namespace App\Contexts\Commerce\Filament\Resources;

use App\Contexts\Commerce\Enums\BillingInterval;
use App\Contexts\Commerce\Enums\CommercePermission;
use App\Contexts\Commerce\Filament\Resources\SubscriptionPlanResource\Pages;
use App\Contexts\Commerce\Filament\Resources\SubscriptionPlanResource\RelationManagers\PricesRelationManager;
use App\Contexts\Commerce\Models\SubscriptionPlan;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Admin CRUD for subscription plans. The plan carries the recurring cadence (BillingInterval), an
 * optional no-charge trial (trial_days), and an active flag; per-currency amounts live in the
 * related prices (managed by the PricesRelationManager) since money is never stored on the plan.
 * The user-visible name/description are authored per locale (Sprint 0.2 i18n convention).
 *
 * Authorization: management is gated on the commerce subscriptions permission (finance_manager), so
 * support/students are denied even though the panel already restricts admin access. All lifecycle
 * math lives in the domain engine — the resource only edits catalogue-side plan data.
 */
class SubscriptionPlanResource extends Resource
{
    protected static ?string $model = SubscriptionPlan::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static string|\UnitEnum|null $navigationGroup = 'Commerce';

    protected static ?string $navigationLabel = 'Subscription Plans';

    protected static ?string $recordRouteKeyName = 'public_id';

    protected static ?string $recordTitleAttribute = 'name';

    /** Whether the signed-in operator may manage subscription plans (finance separation). */
    private static function canManage(): bool
    {
        $user = Auth::user();

        return $user !== null && $user->can(CommercePermission::ManageSubscriptions->value);
    }

    public static function canViewAny(): bool
    {
        return self::canManage();
    }

    public static function canCreate(): bool
    {
        return self::canManage();
    }

    public static function canEdit(Model $record): bool
    {
        return self::canManage();
    }

    public static function canView(Model $record): bool
    {
        return self::canManage();
    }

    public static function canDelete(Model $record): bool
    {
        return self::canManage();
    }

    public static function canDeleteAny(): bool
    {
        return self::canManage();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Content')->columns(2)->schema([
                TextInput::make('name_i18n.en')->label('Name (EN)')->required()->maxLength(255)
                    ->helperText('English is the default and fallback locale.'),
                TextInput::make('name_i18n.ar')->label('Name (AR)')->maxLength(255)
                    ->extraInputAttributes(['dir' => 'rtl']),
                Textarea::make('description_i18n.en')->label('Description (EN)')->rows(3)->columnSpanFull(),
                Textarea::make('description_i18n.ar')->label('Description (AR)')->rows(3)->columnSpanFull()
                    ->extraInputAttributes(['dir' => 'rtl']),
            ]),
            Section::make('Billing')->columns(2)->schema([
                Select::make('interval')
                    ->options(collect(BillingInterval::cases())->mapWithKeys(fn (BillingInterval $i) => [$i->value => ucfirst($i->value)])->all())
                    ->default(BillingInterval::Monthly->value)->required()
                    ->helperText('Recurring cadence; renewals advance the period by this interval.'),
                TextInput::make('trial_days')->label('Trial days')->numeric()->minValue(0)->default(0)
                    ->helperText('A no-charge trial before the first renewal charge (0 = no trial).'),
                Toggle::make('is_active')->label('Active')->default(true)->inline(false)
                    ->helperText('Inactive plans are hidden from new subscribers.'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->label('Name')->searchable()->sortable(),
            TextColumn::make('interval')->badge()
                ->formatStateUsing(fn ($state) => $state instanceof BillingInterval ? ucfirst($state->value) : $state),
            TextColumn::make('trial_days')->label('Trial (days)')->sortable()->toggleable(),
            TextColumn::make('prices_count')->counts('prices')->label('Prices')->toggleable(),
            IconColumn::make('is_active')->boolean()->label('Active'),
            TextColumn::make('updated_at')->dateTime()->since()->label('Updated')->toggleable(),
        ])->defaultSort('id', 'desc')
            ->filters([
                SelectFilter::make('interval')
                    ->options(collect(BillingInterval::cases())->mapWithKeys(fn (BillingInterval $i) => [$i->value => ucfirst($i->value)])->all()),
                TernaryFilter::make('is_active')->label('Active'),
            ]);
    }

    public static function getRelations(): array
    {
        return [PricesRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSubscriptionPlans::route('/'),
            'create' => Pages\CreateSubscriptionPlan::route('/create'),
            'edit' => Pages\EditSubscriptionPlan::route('/{record}/edit'),
        ];
    }
}
