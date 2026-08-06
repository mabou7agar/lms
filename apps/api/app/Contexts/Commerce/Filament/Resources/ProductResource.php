<?php

namespace App\Contexts\Commerce\Filament\Resources;

use App\Contexts\Commerce\Enums\ProductStatus;
use App\Contexts\Commerce\Filament\Resources\ProductResource\Pages;
use App\Contexts\Commerce\Models\Product;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ProductResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-shopping-bag';

    protected static string|\UnitEnum|null $navigationGroup = 'Commerce';

    protected static ?string $recordRouteKeyName = 'public_id';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Content')->columns(2)->schema([
                TextInput::make('title_i18n.en')->label('Title (EN)')->required()->maxLength(255)
                    ->helperText('English is the default and fallback locale.'),
                TextInput::make('title_i18n.ar')->label('Title (AR)')->maxLength(255)
                    ->extraInputAttributes(['dir' => 'rtl']),
                Textarea::make('description_i18n.en')->label('Description (EN)')->rows(3)->columnSpanFull()
                    ->helperText('English is the default and fallback locale.'),
                Textarea::make('description_i18n.ar')->label('Description (AR)')->rows(3)->columnSpanFull()
                    ->extraInputAttributes(['dir' => 'rtl']),
            ]),
            Select::make('status')->options(collect(ProductStatus::cases())->mapWithKeys(fn ($s) => [$s->value => ucfirst($s->value)])->all())->default(ProductStatus::Draft->value),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('title')->searchable()->sortable(),
            TextColumn::make('status')->badge(),
        ])->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProduct::route('/'),
            'create' => Pages\CreateProduct::route('/create'),
            'edit' => Pages\EditProduct::route('/{record}/edit'),
        ];
    }
}
