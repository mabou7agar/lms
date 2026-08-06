<?php

namespace App\Domains\Certification\Filament\Resources;

use App\Domains\Certification\Filament\Resources\BadgeResource\Pages;
use App\Domains\Certification\Models\Badge;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class BadgeResource extends Resource
{
    protected static ?string $model = Badge::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-sparkles';

    protected static string|\UnitEnum|null $navigationGroup = 'Certification';

    protected static ?string $recordRouteKeyName = 'public_id';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Content')->columns(2)->schema([
                TextInput::make('name_i18n.en')->label('Name (EN)')->required()
                    ->helperText('English is the default and fallback locale.'),
                TextInput::make('name_i18n.ar')->label('Name (AR)')
                    ->extraInputAttributes(['dir' => 'rtl']),
                Textarea::make('description_i18n.en')->label('Description (EN)')->rows(2)->columnSpanFull()
                    ->helperText('English is the default and fallback locale.'),
                Textarea::make('description_i18n.ar')->label('Description (AR)')->rows(2)->columnSpanFull()
                    ->extraInputAttributes(['dir' => 'rtl']),
                TextInput::make('criteria_i18n.en')->label('Criteria (EN)')
                    ->helperText('English is the default and fallback locale.'),
                TextInput::make('criteria_i18n.ar')->label('Criteria (AR)')
                    ->extraInputAttributes(['dir' => 'rtl']),
            ]),
            Toggle::make('is_active')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->searchable(),
            IconColumn::make('is_active')->boolean(),
        ])->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBadge::route('/'),
            'create' => Pages\CreateBadge::route('/create'),
            'edit' => Pages\EditBadge::route('/{record}/edit'),
        ];
    }
}
