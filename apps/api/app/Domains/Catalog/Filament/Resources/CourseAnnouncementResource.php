<?php

namespace App\Domains\Catalog\Filament\Resources;

use App\Domains\Catalog\Filament\Resources\CourseAnnouncementResource\Pages;
use App\Domains\Catalog\Models\CourseAnnouncement;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Admin management for course announcements (view/create/edit/delete). Presentation only —
 * persistence is standard Eloquent on the CourseAnnouncement model. Instructor-facing creation
 * (with learner fan-out) happens through the Instructor Portal API, not here.
 */
class CourseAnnouncementResource extends Resource
{
    protected static ?string $model = CourseAnnouncement::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-megaphone';

    protected static string|\UnitEnum|null $navigationGroup = 'Catalog';

    protected static ?string $navigationLabel = 'Announcements';

    protected static ?string $recordRouteKeyName = 'public_id';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('course_id')->relationship('course', 'title')->searchable()->required(),
            TextInput::make('title')->required()->maxLength(160),
            Textarea::make('body')->required()->rows(8),
            DateTimePicker::make('published_at')->timezone(self::adminTimezone())
                ->helperText('When learners see this announcement.'),
        ]);
    }

    /**
     * The signed-in admin's IANA timezone (falling back to the platform default) so the publish
     * picker stores the operator's local wall-clock correctly. Guards a missing/non-IANA value.
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
            TextColumn::make('title')->searchable()->limit(48),
            TextColumn::make('course.title')->label('Course')->searchable()->limit(36)->toggleable(),
            TextColumn::make('published_at')->dateTime()->sortable()->toggleable(),
            TextColumn::make('created_at')->dateTime()->sortable()->toggleable(),
        ])->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAnnouncements::route('/'),
            'create' => Pages\CreateAnnouncement::route('/create'),
            'edit' => Pages\EditAnnouncement::route('/{record}/edit'),
        ];
    }
}
