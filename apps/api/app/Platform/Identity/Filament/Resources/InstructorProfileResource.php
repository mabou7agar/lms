<?php

namespace App\Platform\Identity\Filament\Resources;

use App\Platform\Identity\Filament\Resources\InstructorProfileResource\Pages;
use App\Platform\Identity\Models\UserProfile;
use App\Platform\Shared\Filament\Forms\Components\MediaPicker;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * U4 - Admin editing surface for instructor profiles. Operates over the shared UserProfile row but is
 * scoped to users holding the 'instructor' role (see getEloquentQuery), so it reads as an
 * instructor-only resource without a second table. Bilingual headline/bio use the HasTranslations
 * pattern (EN default/fallback, AR RTL); profile & cover photos use the shared MediaPicker and store
 * a MediaAsset public_id reference (existing path/URL values are preserved until replaced).
 *
 * Profiles are created for a user elsewhere (registration / the User resource), so this resource is
 * edit-only — it never mass-creates orphan profiles.
 *
 * TENANCY NOTE (T1, later phase): getEloquentQuery() and the MediaPicker owner scope will need an
 * organization filter once tenant scoping exists.
 */
class InstructorProfileResource extends Resource
{
    protected static ?string $model = UserProfile::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-user-circle';

    protected static string|\UnitEnum|null $navigationGroup = 'Identity';

    protected static ?string $navigationLabel = 'Instructor Profiles';

    protected static ?string $recordTitleAttribute = 'public_id';

    protected static ?string $recordRouteKeyName = 'public_id';

    /** Only surface profiles that belong to instructor users. */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->whereHas('user.roles', fn (Builder $q): Builder => $q->where('name', 'instructor'));
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Identity')->columns(2)->schema([
                TextInput::make('first_name')->maxLength(255),
                TextInput::make('last_name')->maxLength(255),
            ]),
            Section::make('Headline & Bio')->columns(2)->schema([
                TextInput::make('headline_i18n.en')->label('Headline (EN)')->maxLength(255)
                    ->helperText('English is the default and fallback locale.'),
                TextInput::make('headline_i18n.ar')->label('Headline (AR)')->maxLength(255)
                    ->extraInputAttributes(['dir' => 'rtl']),
                Textarea::make('bio_i18n.en')->label('Bio (EN)')->rows(4)->columnSpanFull()
                    ->helperText('English is the default and fallback locale.'),
                Textarea::make('bio_i18n.ar')->label('Bio (AR)')->rows(4)->columnSpanFull()
                    ->extraInputAttributes(['dir' => 'rtl']),
            ]),
            Section::make('Media')->columns(2)->schema([
                MediaPicker::make('profile_photo')
                    ->label('Profile photo')
                    ->purpose('lesson_image')
                    ->acceptedTypes(['image'])
                    ->allowLegacyUrl()
                    ->reusable()
                    ->searchable()
                    ->helperText('Pick from the media library or upload. Existing paths are kept until replaced.'),
                MediaPicker::make('cover_photo')
                    ->label('Cover photo')
                    ->purpose('lesson_image')
                    ->acceptedTypes(['image'])
                    ->allowLegacyUrl()
                    ->reusable()
                    ->searchable(),
            ]),
            Section::make('Details')->columns(2)->schema([
                TagsInput::make('specialties')->label('Specialties')->columnSpanFull(),
                KeyValue::make('social_links')->label('Social links')
                    ->keyLabel('Platform')->valueLabel('URL')->columnSpanFull(),
                TextInput::make('website')->url()->maxLength(255),
                TextInput::make('display_order')->numeric()->default(0)
                    ->helperText('Lower values appear first in the public directory.'),
                Toggle::make('is_public')->label('Public')->default(true)
                    ->helperText('Show this profile on the public instructor pages.'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('user.name')->label('Instructor')->searchable()->sortable(),
                TextColumn::make('headline')->label('Headline')->limit(40)->toggleable()
                    ->getStateUsing(fn (UserProfile $record): ?string => $record->localized('headline')),
                TextColumn::make('display_order')->label('Order')->sortable(),
                IconColumn::make('is_public')->boolean()->label('Public'),
            ])
            ->defaultSort('display_order');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListInstructorProfiles::route('/'),
            'edit' => Pages\EditInstructorProfile::route('/{record}/edit'),
        ];
    }
}
