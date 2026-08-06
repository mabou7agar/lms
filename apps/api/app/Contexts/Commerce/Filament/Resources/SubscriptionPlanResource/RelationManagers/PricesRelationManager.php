<?php

namespace App\Contexts\Commerce\Filament\Resources\SubscriptionPlanResource\RelationManagers;

use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Per-currency prices for a subscription plan. Amounts are integer minor units only (the plan's
 * recurring charge is read from here, never derived); exactly one row per currency, one flagged as
 * the default. Editing prices does not touch any live subscription — the engine reads the amount at
 * charge time.
 */
class PricesRelationManager extends RelationManager
{
    protected static string $relationship = 'prices';

    protected static ?string $title = 'Prices';

    protected static ?string $recordTitleAttribute = 'currency';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('currency')->label('Currency')->required()
                ->maxLength(3)->minLength(3)
                ->helperText('ISO 4217 code, e.g. SAR, USD, EGP.')
                ->extraInputAttributes(['style' => 'text-transform:uppercase']),
            TextInput::make('amount_minor')->label('Amount (minor units)')->numeric()->required()->minValue(0)
                ->helperText('Integer minor units, e.g. 19900 = 199.00.'),
            Toggle::make('is_default')->label('Default price')->inline(false)
                ->helperText('Used when the requested currency is not published.'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('currency')
            ->defaultSort('currency')
            ->columns([
                TextColumn::make('currency')->label('Currency')->sortable(),
                TextColumn::make('amount_minor')->label('Amount (minor)')->sortable(),
                IconColumn::make('is_default')->boolean()->label('Default'),
            ])
            ->headerActions([CreateAction::make()])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([]);
    }
}
