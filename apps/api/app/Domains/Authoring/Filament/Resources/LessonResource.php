<?php

namespace App\Domains\Authoring\Filament\Resources;

use App\Domains\Authoring\Enums\LessonType;
use App\Domains\Authoring\Enums\PublishState;
use App\Domains\Authoring\Filament\Resources\LessonResource\Pages;
use App\Domains\Authoring\Models\Lesson;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class LessonResource extends Resource
{
    protected static ?string $model = Lesson::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-play-circle';

    protected static string|\UnitEnum|null $navigationGroup = 'Authoring';

    protected static ?string $recordRouteKeyName = 'public_id';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('section_id')->relationship('section', 'title')->searchable()->required(),
            Grid::make(2)->schema([
                TextInput::make('title_i18n.en')->label('Title (EN)')->required()->maxLength(255)
                    ->helperText('English is the default and fallback locale.'),
                TextInput::make('title_i18n.ar')->label('Title (AR)')->maxLength(255)
                    ->extraInputAttributes(['dir' => 'rtl']),
            ]),
            Select::make('type')
                ->options(collect(LessonType::cases())->mapWithKeys(fn ($t) => [$t->value => $t->label()])->all())
                ->required(),
            Select::make('publish_state')
                ->options(collect(PublishState::cases())->mapWithKeys(fn ($s) => [$s->value => ucfirst($s->value)])->all())
                ->default(PublishState::Draft->value),
            Toggle::make('is_preview'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')->searchable()->sortable(),
                TextColumn::make('type')->badge(),
                TextColumn::make('publish_state')->badge(),
                IconColumn::make('is_preview')->boolean(),
            ])
            ->defaultSort('position');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLessons::route('/'),
            'create' => Pages\CreateLesson::route('/create'),
            'edit' => Pages\EditLesson::route('/{record}/edit'),
        ];
    }
}
