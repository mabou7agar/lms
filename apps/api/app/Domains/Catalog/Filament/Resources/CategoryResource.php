<?php

namespace App\Domains\Catalog\Filament\Resources;

use App\Domains\Catalog\Filament\Resources\CategoryResource\Pages;
use App\Domains\Catalog\Models\Category;
use App\Platform\Shared\Filament\Forms\Components\MediaPicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CategoryResource extends Resource
{
    protected static ?string $model = Category::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static string|\UnitEnum|null $navigationGroup = 'Catalog';

    protected static ?string $recordRouteKeyName = 'public_id';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Content')->columns(2)->schema([
                TextInput::make('name_i18n.en')->label('Name (EN)')->required()->maxLength(255)
                    ->helperText('English is the default and fallback locale.'),
                TextInput::make('name_i18n.ar')->label('Name (AR)')->maxLength(255)
                    ->extraInputAttributes(['dir' => 'rtl']),
                Textarea::make('description_i18n.en')->label('Description (EN)')->rows(3)->columnSpanFull()
                    ->helperText('English is the default and fallback locale.'),
                Textarea::make('description_i18n.ar')->label('Description (AR)')->rows(3)->columnSpanFull()
                    ->extraInputAttributes(['dir' => 'rtl']),
            ]),
            Select::make('parent_id')->relationship('parent', 'name')->searchable(),
            MediaPicker::make('image_path')
                ->label('Image / icon')
                ->purpose('lesson_image')
                ->acceptedTypes(['image'])
                ->allowLegacyUrl()
                ->searchable()
                ->helperText('Pick from the media library or upload a new image. Existing URLs are kept until replaced.'),
            TextInput::make('position')->numeric()->default(0),
            Toggle::make('is_active')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('parent.name')->label('Parent')->toggleable(),
                TextColumn::make('position')->sortable(),
                IconColumn::make('is_active')->boolean(),
            ])
            ->defaultSort('position');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCategories::route('/'),
            'create' => Pages\CreateCategory::route('/create'),
            'edit' => Pages\EditCategory::route('/{record}/edit'),
        ];
    }
}
