<?php

namespace App\Domains\Authoring\Filament\Resources;

use App\Domains\Authoring\Enums\PublishState;
use App\Domains\Authoring\Filament\Resources\SectionResource\Pages;
use App\Domains\Authoring\Models\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SectionResource extends Resource
{
    protected static ?string $model = Section::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-list-bullet';

    protected static string|\UnitEnum|null $navigationGroup = 'Authoring';

    protected static ?string $recordRouteKeyName = 'public_id';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('course_id')->relationship('course', 'title')->searchable()->required(),
            Grid::make(2)->schema([
                TextInput::make('title_i18n.en')->label('Title (EN)')->required()->maxLength(255)
                    ->helperText('English is the default and fallback locale.'),
                TextInput::make('title_i18n.ar')->label('Title (AR)')->maxLength(255)
                    ->extraInputAttributes(['dir' => 'rtl']),
                Textarea::make('summary_i18n.en')->label('Summary (EN)')->rows(2)->columnSpanFull()
                    ->helperText('English is the default and fallback locale.'),
                Textarea::make('summary_i18n.ar')->label('Summary (AR)')->rows(2)->columnSpanFull()
                    ->extraInputAttributes(['dir' => 'rtl']),
            ]),
            TextInput::make('position')->numeric()->default(0),
            Select::make('publish_state')
                ->options(collect(PublishState::cases())->mapWithKeys(fn ($s) => [$s->value => ucfirst($s->value)])->all())
                ->default(PublishState::Draft->value),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')->searchable()->sortable(),
                TextColumn::make('course.title')->label('Course')->toggleable(),
                TextColumn::make('publish_state')->badge(),
                TextColumn::make('position')->sortable(),
            ])
            ->defaultSort('position');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSections::route('/'),
            'create' => Pages\CreateSection::route('/create'),
            'edit' => Pages\EditSection::route('/{record}/edit'),
        ];
    }
}
