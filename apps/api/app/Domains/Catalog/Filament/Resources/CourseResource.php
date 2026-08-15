<?php

namespace App\Domains\Catalog\Filament\Resources;

use App\Domains\Catalog\Actions\Course\DuplicateCourseAction;
use App\Domains\Catalog\Enums\CatalogPermission;
use App\Domains\Catalog\Enums\CourseStatus;
use App\Domains\Catalog\Filament\Resources\CourseResource\Pages;
use App\Domains\Catalog\Filament\Resources\CourseResource\RelationManagers\GalleryRelationManager;
use App\Domains\Catalog\Filament\Resources\CourseResource\RelationManagers\InstructorsRelationManager;
use App\Domains\Catalog\Models\Course;
use App\Domains\Catalog\Services\CourseLifecycle;
use App\Platform\Identity\Contracts\Actor;
use App\Platform\Shared\Enums\Visibility;
use App\Platform\Shared\Filament\Forms\Components\MediaPicker;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;
use Throwable;

class CourseResource extends Resource
{
    protected static ?string $model = Course::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-academic-cap';

    protected static string|\UnitEnum|null $navigationGroup = 'Catalog';

    protected static ?string $recordRouteKeyName = 'public_id';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Content')->columns(2)->schema([
                TextInput::make('title_i18n.en')->label('Title (EN)')->required()->maxLength(255)
                    ->helperText('English is the default and fallback locale.')
                    ->live(onBlur: true)
                    // Auto-suggest a slug from the English title while the slug is still blank; an
                    // operator-typed slug is never overwritten.
                    ->afterStateUpdated(function (Get $get, Set $set, ?string $state): void {
                        if (blank($get('slug')) && filled($state)) {
                            $set('slug', Str::slug((string) $state));
                        }
                    }),
                TextInput::make('title_i18n.ar')->label('Title (AR)')->maxLength(255)
                    ->extraInputAttributes(['dir' => 'rtl']),
                TextInput::make('subtitle_i18n.en')->label('Subtitle (EN)')->maxLength(255)
                    ->helperText('English is the default and fallback locale.'),
                TextInput::make('subtitle_i18n.ar')->label('Subtitle (AR)')->maxLength(255)
                    ->extraInputAttributes(['dir' => 'rtl']),
                Textarea::make('description_i18n.en')->label('Description (EN)')->rows(4)->columnSpanFull()
                    ->helperText('English is the default and fallback locale.'),
                Textarea::make('description_i18n.ar')->label('Description (AR)')->rows(4)->columnSpanFull()
                    ->extraInputAttributes(['dir' => 'rtl']),
                TextInput::make('slug')->label('Slug')->maxLength(255)
                    ->unique(ignoreRecord: true)
                    ->helperText('URL identifier. Auto-suggested from the English title; editable and must be unique.'),
            ]),
            // Status is READ-ONLY here: it may only change through the guarded lifecycle actions
            // (Submit for review / Approve / Schedule / Publish / Unpublish / Archive / Restore),
            // which route through the CourseLifecycle state machine + publish readiness guard. A raw
            // Select would bypass both. New courses start Draft (the DB default).
            Placeholder::make('status_display')
                ->label('Status')
                ->content(fn (?Course $record): string => ($record?->status ?? CourseStatus::Draft)->label()),
            Select::make('visibility')
                ->options(collect(Visibility::cases())->mapWithKeys(fn ($v) => [$v->value => $v->label()])->all())
                ->default(Visibility::Public->value),
            Select::make('level_id')->relationship('level', 'name')->searchable(),
            Select::make('language_id')->relationship('language', 'name')->searchable(),
            Toggle::make('is_featured'),
            Section::make('Taxonomy')->columns(2)->schema([
                Select::make('categories')
                    ->relationship('categories', 'name', fn (\Illuminate\Database\Eloquent\Builder $query) => $query->select(['categories.id', 'categories.name']))
                    ->multiple()->preload()->searchable()
                    ->helperText('Catalog categories this course belongs to (course_category).'),
                Select::make('tags')
                    ->relationship('tags', 'name', fn (\Illuminate\Database\Eloquent\Builder $query) => $query->select(['course_tags.id', 'course_tags.name']))
                    ->multiple()->preload()->searchable()
                    ->helperText('Free-form marketing tags (course_tag).'),
            ]),
            Section::make('Marketing')->columns(2)->schema([
                TagsInput::make('learning_objectives_i18n.en')->label('Learning objectives (EN)')
                    ->helperText('What a learner will be able to do. Press Enter after each item.'),
                TagsInput::make('learning_objectives_i18n.ar')->label('Learning objectives (AR)')
                    ->extraInputAttributes(['dir' => 'rtl']),
                TagsInput::make('requirements_i18n.en')->label('Requirements (EN)')
                    ->helperText('Prerequisites/knowledge needed before starting.'),
                TagsInput::make('requirements_i18n.ar')->label('Requirements (AR)')
                    ->extraInputAttributes(['dir' => 'rtl']),
                TagsInput::make('target_audience_i18n.en')->label('Target audience (EN)')
                    ->helperText('Who this course is for.'),
                TagsInput::make('target_audience_i18n.ar')->label('Target audience (AR)')
                    ->extraInputAttributes(['dir' => 'rtl']),
                TextInput::make('duration_minutes')->label('Duration (minutes)')->numeric()->minValue(0)
                    ->helperText('Optional manual/override total duration, in minutes.'),
                MediaPicker::make('trailer_path')
                    ->label('Promo trailer')
                    ->purpose('lesson_video')
                    ->acceptedTypes(['video'])
                    ->allowLegacyUrl()
                    ->searchable()
                    ->helperText('Pick a promo video from the media library or upload one. Existing URLs are kept until replaced.'),
            ]),
            MediaPicker::make('thumbnail_path')
                ->label('Thumbnail')
                ->purpose('lesson_image')
                ->acceptedTypes(['image'])
                ->allowLegacyUrl()
                ->searchable()
                // Course cards render a 16:9 thumbnail — crop to that frame on upload.
                ->imageAspectRatios(['16:9'])
                ->helperText('Pick from the media library or upload a new image. Crop to the 16:9 card frame. Existing URLs are kept until replaced.'),
            Section::make('SEO')->columns(2)->schema([
                TextInput::make('seo.meta_title')->label('Meta title')->maxLength(255),
                TextInput::make('seo.canonical')->label('Canonical URL')->maxLength(2048)->url(),
                Textarea::make('seo.meta_description')->label('Meta description')->rows(2)->columnSpanFull(),
                TextInput::make('seo.og_image')->label('OG image URL')->maxLength(2048)->url()->columnSpanFull(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')->searchable()->sortable()->limit(40),
                TextColumn::make('status')->badge()->color(fn (CourseStatus $state): string => $state->color()),
                IconColumn::make('is_featured')->boolean(),
                TextColumn::make('scheduled_publish_at')->dateTime()->sortable()->toggleable(),
                TextColumn::make('published_at')->dateTime()->sortable()->toggleable(),
            ])
            ->recordActions([...self::lifecycleActions(), self::duplicateAction()])
            ->defaultSort('id', 'desc');
    }

    public static function getRelations(): array
    {
        return [GalleryRelationManager::class, InstructorsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCourses::route('/'),
            'create' => Pages\CreateCourse::route('/create'),
            'edit' => Pages\EditCourse::route('/{record}/edit'),
        ];
    }

    /**
     * The guarded lifecycle actions, reused by the edit-page header and the list-table rows. Each is
     * visible only when the operator may manage courses AND the state machine permits the move from
     * the record's current status, and each surfaces guard/transition failures as a notification.
     *
     * @return array<int, Action>
     */
    public static function lifecycleActions(): array
    {
        return [
            self::transitionAction('submitForReview', 'Submit for review', 'heroicon-o-paper-airplane', 'warning', CourseStatus::Review),
            self::transitionAction('approveCourse', 'Approve', 'heroicon-o-check-badge', 'info', CourseStatus::Approved),
            self::scheduleAction(),
            self::transitionAction('publishCourse', 'Publish', 'heroicon-o-rocket-launch', 'success', CourseStatus::Published),
            self::transitionAction('unpublishCourse', 'Unpublish', 'heroicon-o-eye-slash', 'gray', CourseStatus::Unpublished),
            self::transitionAction('archiveCourse', 'Archive', 'heroicon-o-archive-box', 'danger', CourseStatus::Archived),
            self::transitionAction('restoreCourse', 'Restore', 'heroicon-o-arrow-uturn-left', 'info', CourseStatus::Draft),
        ];
    }

    /**
     * REPLICATE/DUPLICATE — clone the course into a new independent Draft (fresh public_id/slug,
     * " (Copy)" title, catalog associations + curriculum copied, publish state reset, tenant stamped
     * server-side). Delegates entirely to DuplicateCourseAction; visible only to course managers.
     */
    public static function duplicateAction(): Action
    {
        return Action::make('duplicate')
            ->label('Duplicate')
            ->icon('heroicon-o-document-duplicate')
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading('Duplicate course')
            ->modalDescription('Create an independent Draft copy of this course — title suffixed " (Copy)" — including its categories, tags, instructors, gallery and curriculum. The copy is unpublished and unfeatured.')
            ->visible(fn (Course $record): bool => self::userCanManage())
            ->action(function (Course $record): void {
                try {
                    $copy = app(DuplicateCourseAction::class)->execute($record);
                    Notification::make()->title(sprintf('Course duplicated as "%s".', $copy->title))->success()->send();
                } catch (Throwable $e) {
                    Notification::make()->title('Duplicate failed')->body($e->getMessage())->danger()->send();
                }
            });
    }

    /** A single guarded status transition with no extra input. */
    private static function transitionAction(string $name, string $label, string $icon, string $color, CourseStatus $to): Action
    {
        return Action::make($name)
            ->label($label)
            ->icon($icon)
            ->color($color)
            ->requiresConfirmation()
            ->visible(fn (Course $record): bool => self::userCanManage()
                && app(CourseLifecycle::class)->canTransition($record->status, $to))
            ->action(fn (Course $record) => self::runTransition($record, $to));
    }

    /** Scheduling needs a future publish time, so it opens a small modal form. */
    private static function scheduleAction(): Action
    {
        return Action::make('scheduleCourse')
            ->label('Schedule…')
            ->icon('heroicon-o-clock')
            ->color('info')
            ->schema([
                DateTimePicker::make('scheduled_publish_at')
                    ->label('Publish at')
                    ->seconds(false)
                    ->required()
                    ->helperText('Must be in the future. The course auto-publishes then, if it passes readiness.'),
            ])
            ->visible(fn (Course $record): bool => self::userCanManage()
                && app(CourseLifecycle::class)->canTransition($record->status, CourseStatus::Scheduled))
            ->action(function (array $data, Course $record): void {
                self::runTransition(
                    $record,
                    CourseStatus::Scheduled,
                    new \DateTimeImmutable((string) $data['scheduled_publish_at']),
                );
            });
    }

    /** Invoke the state machine and translate its outcome into a Filament notification. */
    private static function runTransition(Course $record, CourseStatus $to, ?\DateTimeInterface $scheduledPublishAt = null): void
    {
        try {
            app(CourseLifecycle::class)->transition($record, $to, self::actor(), $scheduledPublishAt);
            Notification::make()->title(sprintf('Course moved to %s.', $to->label()))->success()->send();
        } catch (Throwable $e) {
            // Surface the guard/transition rejection verbatim; never bypass it.
            Notification::make()->title('Transition failed')->body($e->getMessage())->danger()->send();
        }
    }

    /**
     * Mirror CoursePolicy: only a super_admin or a holder of the real ManageCourses permission may
     * drive the lifecycle from the admin panel.
     */
    public static function userCanManage(): bool
    {
        $user = self::actor();

        if (! $user instanceof Actor) {
            return false;
        }

        return $user->hasRole('super_admin') || $user->hasPermission(CatalogPermission::ManageCourses->value);
    }

    private static function actor(): ?Actor
    {
        $user = Filament::auth()->user() ?? auth()->user();

        return $user instanceof Actor ? $user : null;
    }
}
