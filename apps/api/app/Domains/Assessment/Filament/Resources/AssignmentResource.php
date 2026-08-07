<?php

namespace App\Domains\Assessment\Filament\Resources;

use App\Domains\Assessment\Enums\AssignmentState;
use App\Domains\Assessment\Enums\LatePolicy;
use App\Domains\Assessment\Enums\SubmissionType;
use App\Domains\Assessment\Filament\Resources\AssignmentResource\Pages;
use App\Domains\Assessment\Filament\Resources\AssignmentResource\RelationManagers\RubricsRelationManager;
use App\Domains\Assessment\Models\Assignment;
use App\Domains\Assessment\Services\AssignmentService;
use App\Platform\Identity\Contracts\Actor;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Admin authoring surface for instructor-reviewed assignments. The form binds to the Assignment
 * model (its casts and the HasTranslations title sync apply as for the API). The bilingual
 * `instructions` payload is authored as localized rich text through the same HtmlSanitizer strategy
 * the assessment question fields use — the model declares `instructions` translatableHtml, so each
 * locale value is sanitized on save; it is never exposed as a raw JSON editor.
 *
 * The publish lifecycle is not a form field: the Publish / Unpublish record actions delegate to
 * AssignmentService (which audits and dispatches AssignmentPublished). Rubric authoring lives in the
 * RubricsRelationManager, which delegates to AssignmentService::buildRubric so the deterministic
 * point roll-ups are computed by the engine, never the UI. Per-record authorization is left to
 * AssignmentPolicy (course ownership via the `assignment.manage-assignment` gate).
 */
class AssignmentResource extends Resource
{
    protected static ?string $model = Assignment::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document-text';

    protected static string|\UnitEnum|null $navigationGroup = 'Assessment';

    protected static ?string $navigationLabel = 'Assignments';

    protected static ?string $recordRouteKeyName = 'public_id';

    protected static ?string $recordTitleAttribute = 'title';

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user instanceof Actor && $user->hasRole(['admin', 'super_admin']);
    }

    public static function canViewAny(): bool
    {
        return self::canAccess();
    }

    public static function canCreate(): bool
    {
        return self::canAccess();
    }

    /** The signed-in admin's IANA timezone (falling back to the platform default) for the due-date picker. */
    private static function adminTimezone(): string
    {
        $timezone = Auth::user()?->timezone ?? config('shared.default_timezone', 'UTC');

        return is_string($timezone) && in_array($timezone, timezone_identifiers_list(), true)
            ? $timezone
            : 'UTC';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Identity')->columns(2)->schema([
                Select::make('course_id')->label('Course')
                    ->options(fn (): array => DB::table('courses')->orderBy('title')->pluck('title', 'id')->all())
                    ->searchable()->required()
                    ->helperText('The authorization anchor — an instructor may manage a course\'s assignments if they train it.'),
                Select::make('lesson_id')->label('Lesson (optional placement)')
                    ->options(fn (): array => DB::table('lessons')->orderBy('title')->pluck('title', 'id')->all())
                    ->searchable()
                    ->helperText('Optional curriculum placement. Leave empty for a course-level assignment.'),
                TextInput::make('title_i18n.en')->label('Title (EN)')->required()->maxLength(255)
                    ->helperText('English is the default and fallback locale.'),
                TextInput::make('title_i18n.ar')->label('Title (AR)')->maxLength(255)
                    ->extraInputAttributes(['dir' => 'rtl']),
            ]),
            Section::make('Instructions')->columns(2)->schema([
                RichEditor::make('instructions.en')->label('Instructions (EN)')
                    ->helperText('Bilingual rich text. HTML is sanitized on save.'),
                RichEditor::make('instructions.ar')->label('Instructions (AR)')
                    ->extraInputAttributes(['dir' => 'rtl']),
            ]),
            Section::make('Submission')->columns(2)->schema([
                Select::make('submission_type')->label('Submission type')
                    ->options(self::enumOptions(SubmissionType::cases()))
                    ->default(SubmissionType::File->value)->required(),
                TagsInput::make('allowed_file_types')->label('Allowed file types')
                    ->helperText('Extensions without the dot, e.g. pdf, docx. Leave empty for the platform default.'),
                TextInput::make('max_file_size')->label('Max file size (bytes)')->numeric()->minValue(1)
                    ->helperText('Leave empty for the platform default.'),
                TextInput::make('max_files')->label('Max files')->numeric()->default(1)->minValue(1)->required(),
                TextInput::make('attempt_limit')->label('Attempt limit')->numeric()->minValue(1)
                    ->helperText('Leave empty for unlimited resubmissions.'),
            ]),
            Section::make('Due date & grading')->columns(2)->schema([
                DateTimePicker::make('due_at')->label('Due at')->timezone(self::adminTimezone())
                    ->helperText('Leave empty for no deadline.'),
                Select::make('late_policy')->label('Late policy')
                    ->options(self::enumOptions(LatePolicy::cases()))
                    ->default(LatePolicy::Allowed->value)->required(),
                TextInput::make('late_penalty_percent')->label('Late penalty (%)')->numeric()->minValue(0)->maxValue(100)
                    ->helperText('Applied only when the late policy is "penalised".'),
                TextInput::make('max_grade')->label('Max grade')->numeric()->default(100)->minValue(0)->required(),
                TextInput::make('passing_grade')->label('Passing grade')->numeric()->minValue(0)
                    ->helperText('Leave empty for accept/return only (no pass mark).'),
                Toggle::make('required_for_completion')->label('Required for completion')->inline(false),
                Select::make('rubric_id')->label('Active rubric')
                    ->options(fn (?Assignment $record): array => $record === null
                        ? []
                        : $record->rubrics()->pluck('title', 'id')->all())
                    ->placeholder('None')
                    ->helperText('Which authored rubric grades this assignment. Build rubrics in the Rubric panel on this page after saving.'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('updated_at', 'desc')
            ->columns([
                TextColumn::make('title_i18n.en')->label('Title')->searchable()->wrap(),
                TextColumn::make('submission_type')->badge()->toggleable()
                    ->formatStateUsing(fn ($state): string => $state instanceof SubmissionType ? ucfirst(str_replace('_', ' ', $state->value)) : (string) $state),
                TextColumn::make('publish_state')->badge()
                    ->formatStateUsing(fn ($state): string => $state instanceof AssignmentState ? ucfirst($state->value) : (string) $state)
                    ->color(fn ($state): string => $state === AssignmentState::Published ? 'success' : 'gray'),
                TextColumn::make('due_at')->dateTime()->placeholder('—')->label('Due')->toggleable(),
                TextColumn::make('max_grade')->label('Max grade')->toggleable(),
                IconColumn::make('required_for_completion')->boolean()->label('Required')->toggleable(),
                TextColumn::make('updated_at')->dateTime()->since()->label('Updated')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('publish_state')->options(self::enumOptions(AssignmentState::cases())),
                SelectFilter::make('submission_type')->options(self::enumOptions(SubmissionType::cases())),
            ])
            ->recordActions([
                Action::make('publish')
                    ->label('Publish')
                    ->icon('heroicon-o-rocket-launch')
                    ->color('success')
                    ->visible(fn (Assignment $record): bool => $record->publish_state !== AssignmentState::Published)
                    ->requiresConfirmation()
                    ->modalDescription('Publish this assignment? Learners can see and submit against it.')
                    ->action(function (Assignment $record): void {
                        app(AssignmentService::class)->publish($record);
                        Notification::make()->title('Assignment published')->success()->send();
                    }),
                Action::make('unpublish')
                    ->label('Unpublish')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('warning')
                    ->visible(fn (Assignment $record): bool => $record->publish_state === AssignmentState::Published)
                    ->requiresConfirmation()
                    ->modalDescription('Hide this assignment again? Learner work already recorded is preserved.')
                    ->action(function (Assignment $record): void {
                        app(AssignmentService::class)->unpublish($record);
                        Notification::make()->title('Assignment unpublished')->success()->send();
                    }),
            ]);
    }

    public static function getRelations(): array
    {
        return [RubricsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAssignments::route('/'),
            'create' => Pages\CreateAssignment::route('/create'),
            'edit' => Pages\EditAssignment::route('/{record}/edit'),
        ];
    }

    /**
     * @param  array<int, \BackedEnum>  $cases
     * @return array<string, string>
     */
    private static function enumOptions(array $cases): array
    {
        $options = [];

        foreach ($cases as $case) {
            $options[(string) $case->value] = ucfirst(str_replace('_', ' ', (string) $case->value));
        }

        return $options;
    }
}
