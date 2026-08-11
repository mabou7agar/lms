<?php

namespace App\Domains\Crm\Filament\Resources;

use App\Domains\Crm\Filament\Resources\ContactResource\Pages;
use App\Domains\Crm\Models\Contact;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ContactResource extends Resource
{
    protected static ?string $model = Contact::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-identification';

    protected static string|\UnitEnum|null $navigationGroup = 'CRM';

    protected static ?string $recordRouteKeyName = 'public_id';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('first_name')->required(),
            TextInput::make('last_name'),
            TextInput::make('email')->email(),
            TextInput::make('phone'),
            TextInput::make('title'),
            Select::make('company_id')->relationship('company', 'name')->searchable(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('first_name')->label('First name')->searchable(),
            TextColumn::make('last_name')->label('Last name')->searchable()->toggleable(),
            TextColumn::make('email')->searchable()->toggleable(),
            TextColumn::make('company.name')->label('Company')->toggleable(),
            TextColumn::make('title')->toggleable(),
        ])->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListContact::route('/'),
            'create' => Pages\CreateContact::route('/create'),
            'edit' => Pages\EditContact::route('/{record}/edit'),
        ];
    }
}
