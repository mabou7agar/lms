<?php

namespace App\Domains\Live\Filament\Resources;

use App\Domains\Live\Enums\LiveSessionStatus;
use App\Domains\Live\Filament\Resources\LiveSessionResource\Pages;
use App\Domains\Live\Models\LiveSession;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class LiveSessionResource extends Resource
{
    protected static ?string $model = LiveSession::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-calendar-days';

    protected static string|\UnitEnum|null $navigationGroup = 'Live';

    protected static ?string $recordRouteKeyName = 'public_id';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')->required(),
            TextInput::make('timezone')->default('UTC'),
            DateTimePicker::make('starts_at')->required()->timezone(self::adminTimezone()),
            DateTimePicker::make('ends_at')->required()->timezone(self::adminTimezone()),
            TextInput::make('capacity')->numeric(),
            Select::make('status')->options(collect(LiveSessionStatus::cases())->mapWithKeys(fn ($s) => [$s->value => ucfirst($s->value)])->all()),
        ]);
    }

    /**
     * The signed-in admin's IANA timezone (falling back to the platform default) so the schedule
     * pickers store the operator's local wall-clock correctly. Guards a missing/non-IANA value.
     */
    private static function adminTimezone(): string
    {
        $timezone = auth()->user()?->timezone ?? config('shared.default_timezone', 'UTC');

        return is_string($timezone) && in_array($timezone, timezone_identifiers_list(), true)
            ? $timezone
            : 'UTC';
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('title')->searchable(),
            TextColumn::make('status')->badge(),
            TextColumn::make('starts_at')->dateTime()->sortable(),
            TextColumn::make('capacity'),
        ])->defaultSort('starts_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLiveSession::route('/'),
            'create' => Pages\CreateLiveSession::route('/create'),
            'edit' => Pages\EditLiveSession::route('/{record}/edit'),
        ];
    }
}
