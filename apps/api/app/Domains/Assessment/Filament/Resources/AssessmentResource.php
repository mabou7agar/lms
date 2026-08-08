<?php

namespace App\Domains\Assessment\Filament\Resources;

use App\Domains\Assessment\Actions\Assessment\AttachAssessmentToLessonAction;
use App\Domains\Assessment\Actions\Assessment\DuplicateAssessmentAction;
use App\Domains\Assessment\Actions\Assessment\SetAssessmentStatusAction;
use App\Domains\Assessment\Enums\AssessmentScope;
use App\Domains\Assessment\Enums\AssessmentStatus;
use App\Domains\Assessment\Enums\FeedbackMode;
use App\Domains\Assessment\Filament\Resources\AssessmentResource\Pages;
use App\Domains\Assessment\Filament\Resources\AssessmentResource\RelationManagers\QuestionsRelationManager;
use App\Domains\Assessment\Filament\Support\AssessmentPreviewRenderer;
use App\Domains\Assessment\Models\Assessment;
use App\Platform\Identity\Contracts\Actor;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
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
use Illuminate\Validation\ValidationException;

/**
 * Admin authoring surface for reusable assessments. The form binds straight to the Assessment
 * model, so its casts, the HasTranslations saving hook (which syncs each `{field}_i18n` map to its
 * legacy scalar) and every fillable guard apply exactly as they do for the API. Bilingual title /
 * description are authored side-by-side (EN required — the legacy `title` column is NOT NULL).
 *
 * The publish lifecycle is NEVER edited as a form field: the Publish / Unpublish / Archive record
 * actions delegate to SetAssessmentStatusAction, so the AssessmentPublishGuard runs on the way into
 * `published` exactly as it does through the API. Per-record authorization (view/update/delete) is
 * left to AssessmentPolicy, which resolves instructor course-ownership through the
 * `assessment.manage-assessment` gate; the resource-level gates below keep the panel admin-only.
 */
class AssessmentResource extends Resource
{
    protected static ?string $model = Assessment::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static string|\UnitEnum|null $navigationGroup = 'Assessment';

    protected static ?string $navigationLabel = 'Assessments';

    protected static ?string $recordRouteKeyName = 'public_id';

    protected static ?string $recordTitleAttribute = 'title';

    /** The admin panel is admin-only (User::canAccessPanel); mirror it here as defence in depth. */
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

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Identity')->columns(2)->schema([
                Select::make('course_id')->label('Course')
                    ->options(fn (): array => DB::table('courses')->orderBy('title')->pluck('title', 'id')->all())
                    ->searchable()
                    ->helperText('Leave empty for a platform-level bank (admin-managed, unattached to a course).'),
                Select::make('scope')->label('Scope')
                    ->options(self::enumOptions(AssessmentScope::cases()))
                    ->default(AssessmentScope::Lesson->value)->required()
                    ->helperText('What the assessment is for. V1 attaches only lesson-scoped assessments.'),
                TextInput::make('title_i18n.en')->label('Title (EN)')->required()->maxLength(255)
                    ->helperText('English is the default and fallback locale.'),
                TextInput::make('title_i18n.ar')->label('Title (AR)')->maxLength(255)
                    ->extraInputAttributes(['dir' => 'rtl']),
                Textarea::make('description_i18n.en')->label('Description (EN)')->rows(3)->columnSpanFull(),
                Textarea::make('description_i18n.ar')->label('Description (AR)')->rows(3)->columnSpanFull()
                    ->extraInputAttributes(['dir' => 'rtl']),
            ]),
            Section::make('Grading')->columns(2)->schema([
                TextInput::make('passing_score')->label('Passing score (%)')->numeric()
                    ->minValue(0)->maxValue(100)
                    ->helperText('Percentage 0–100. Leave empty for an ungraded assessment.'),
                Toggle::make('required_for_completion')->label('Required for completion')->inline(false)
                    ->helperText('When a course policy requires quizzes (or names this as its final exam), the learner must pass this assessment before the course completes.'),
                Toggle::make('negative_marking')->label('Negative marking')->inline(false)
                    ->helperText('Deduct a question\'s negative points for a wrong answer.'),
                Select::make('feedback_mode')->label('Feedback / result visibility')
                    ->options(self::enumOptions(FeedbackMode::cases()))
                    ->default(FeedbackMode::AfterSubmit->value)->required()
                    ->helperText('When the answer key and explanations are revealed to the learner.'),
            ]),
            Section::make('Delivery')->columns(2)->schema([
                TextInput::make('max_attempts')->label('Max attempts')->numeric()->minValue(1)
                    ->helperText('Leave empty for unlimited attempts.'),
                TextInput::make('time_limit_seconds')->label('Time limit (seconds)')->numeric()->minValue(1)
                    ->helperText('Leave empty for an untimed assessment.'),
                TextInput::make('questions_per_attempt')->label('Questions per attempt')->numeric()->minValue(1)
                    ->helperText('Serve a random subset per attempt. Leave empty to serve every question.'),
                Toggle::make('shuffle_questions')->label('Shuffle questions')->inline(false),
                Toggle::make('shuffle_options')->label('Shuffle options')->inline(false),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('updated_at', 'desc')
            ->columns([
                TextColumn::make('title_i18n.en')->label('Title')->searchable()->wrap(),
                TextColumn::make('scope')->badge()->toggleable()
                    ->formatStateUsing(fn ($state): string => $state instanceof AssessmentScope ? ucfirst($state->value) : (string) $state),
                TextColumn::make('status')->badge()
                    ->formatStateUsing(fn ($state): string => $state instanceof AssessmentStatus ? ucfirst($state->value) : (string) $state)
                    ->color(fn ($state): string => $state === AssessmentStatus::Published ? 'success' : ($state === AssessmentStatus::Archived ? 'gray' : 'warning')),
                TextColumn::make('passing_score')->label('Pass %')->placeholder('—')->toggleable(),
                TextColumn::make('questions_count')->counts('questions')->label('Questions')->toggleable(),
                IconColumn::make('negative_marking')->boolean()->label('Neg.')->toggleable(),
                TextColumn::make('updated_at')->dateTime()->since()->label('Updated')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(self::enumOptions(AssessmentStatus::cases())),
                SelectFilter::make('scope')->options(self::enumOptions(AssessmentScope::cases())),
            ])
            ->recordActions([
                Action::make('publish')
                    ->label('Publish')
                    ->icon('heroicon-o-rocket-launch')
                    ->color('success')
                    ->visible(fn (Assessment $record): bool => $record->status !== AssessmentStatus::Published)
                    ->requiresConfirmation()
                    ->modalDescription('Publish this assessment? It becomes attemptable by learners. Publishing is guarded — an incomplete assessment is rejected.')
                    ->action(fn (Assessment $record) => self::transition($record, AssessmentStatus::Published, 'Assessment published')),
                Action::make('unpublish')
                    ->label('Unpublish')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('warning')
                    ->visible(fn (Assessment $record): bool => $record->status === AssessmentStatus::Published)
                    ->requiresConfirmation()
                    ->modalDescription('Return this assessment to draft? Learners can no longer start new attempts.')
                    ->action(fn (Assessment $record) => self::transition($record, AssessmentStatus::Draft, 'Assessment unpublished')),
                Action::make('archive')
                    ->label('Archive')
                    ->icon('heroicon-o-archive-box')
                    ->color('gray')
                    ->visible(fn (Assessment $record): bool => $record->status !== AssessmentStatus::Archived)
                    ->requiresConfirmation()
                    ->modalDescription('Archive this assessment? It stays readable for historical attempts but cannot be attached to new lessons.')
                    ->action(fn (Assessment $record) => self::transition($record, AssessmentStatus::Archived, 'Assessment archived')),
                self::previewAction(),
                self::duplicateAction(),
                self::attachToLessonAction(),
                self::detachFromLessonAction(),
            ]);
    }

    /**
     * A1 — deep-duplicate an assessment (fresh ids, forced Draft, questions + options + tags copied,
     * NO attempts). Gated by AssessmentPolicy's `update` (course ownership); the domain action writes
     * the `assessment.duplicated` audit entry.
     */
    private static function duplicateAction(): Action
    {
        return Action::make('duplicate')
            ->label('Duplicate')
            ->icon('heroicon-o-document-duplicate')
            ->color('gray')
            ->visible(fn (Assessment $record): bool => (bool) Auth::user()?->can('update', $record))
            ->requiresConfirmation()
            ->modalDescription('Create an editable Draft copy of this assessment, including its questions and options. Learner attempts and results are NOT copied.')
            ->action(function (Assessment $record): void {
                $copy = app(DuplicateAssessmentAction::class)->execute($record, Auth::id());

                Notification::make()
                    ->title('Assessment duplicated')
                    ->body('A Draft copy was created with '.$copy->questions->count().' question(s).')
                    ->success()
                    ->send();
            });
    }

    /**
     * A2 — read-only, learner-style bilingual preview. Renders every runtime question type in EN and
     * AR (dir=rtl) from already-stored rows; it creates no attempt and grades nothing.
     */
    private static function previewAction(): Action
    {
        return Action::make('preview')
            ->label('Preview')
            ->icon('heroicon-o-eye')
            ->color('gray')
            ->visible(fn (Assessment $record): bool => (bool) Auth::user()?->can('view', $record))
            ->modalHeading('Learner preview')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close')
            ->modalContent(fn (Assessment $record) => app(AssessmentPreviewRenderer::class)->render($record));
    }

    /**
     * A5 — attach this assessment to a lesson via the existing `lessons.assessment_id` reference,
     * scoped to the assessment's own course. Hidden for platform-level banks (no course) and archived
     * assessments; gated by AssessmentPolicy's `update`.
     */
    private static function attachToLessonAction(): Action
    {
        return Action::make('attachToLesson')
            ->label('Attach to lesson')
            ->icon('heroicon-o-link')
            ->color('gray')
            ->visible(fn (Assessment $record): bool => $record->course_id !== null
                && $record->status !== AssessmentStatus::Archived
                && (bool) Auth::user()?->can('update', $record))
            ->modalDescription('Point a lesson in this course at this assessment. Only lessons from the same course are eligible.')
            ->schema([
                Select::make('lesson_id')->label('Lesson')->required()->searchable()
                    ->options(fn (Assessment $record): array => DB::table('lessons')
                        ->join('course_sections', 'lessons.section_id', '=', 'course_sections.id')
                        ->where('course_sections.course_id', $record->course_id)
                        ->orderBy('lessons.title')
                        ->pluck('lessons.title', 'lessons.id')
                        ->all()),
            ])
            ->action(function (array $data, Assessment $record): void {
                try {
                    app(AttachAssessmentToLessonAction::class)->attach($record, (int) $data['lesson_id']);
                } catch (ValidationException $exception) {
                    Notification::make()->title('Cannot attach')->body($exception->getMessage())->danger()->send();

                    return;
                }

                Notification::make()->title('Attached to lesson')->success()->send();
            });
    }

    /**
     * A5 — detach this assessment from a lesson currently referencing it. Visible only when at least
     * one lesson points here; allowed even for archived assessments so a bad one can be pulled.
     */
    private static function detachFromLessonAction(): Action
    {
        return Action::make('detachFromLesson')
            ->label('Detach from lesson')
            ->icon('heroicon-o-link-slash')
            ->color('gray')
            ->visible(fn (Assessment $record): bool => (bool) Auth::user()?->can('update', $record)
                && DB::table('lessons')->where('assessment_id', $record->id)->exists())
            ->modalDescription('Clear this assessment from a lesson that currently references it.')
            ->schema([
                Select::make('lesson_id')->label('Lesson')->required()->searchable()
                    ->options(fn (Assessment $record): array => DB::table('lessons')
                        ->where('assessment_id', $record->id)
                        ->orderBy('title')
                        ->pluck('title', 'id')
                        ->all()),
            ])
            ->action(function (array $data, Assessment $record): void {
                app(AttachAssessmentToLessonAction::class)->detach($record, (int) $data['lesson_id']);

                Notification::make()->title('Detached from lesson')->success()->send();
            });
    }

    public static function getRelations(): array
    {
        return [QuestionsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAssessments::route('/'),
            'create' => Pages\CreateAssessment::route('/create'),
            'edit' => Pages\EditAssessment::route('/{record}/edit'),
        ];
    }

    /**
     * Drive a status change through the domain action so the AssessmentPublishGuard runs on the way
     * into `published`. A guard failure surfaces as a danger notification rather than a 500.
     */
    private static function transition(Assessment $record, AssessmentStatus $status, string $success): void
    {
        try {
            app(SetAssessmentStatusAction::class)->execute($record, $status);
        } catch (ValidationException $exception) {
            Notification::make()->title('Cannot publish')->body($exception->getMessage())->danger()->send();

            return;
        }

        Notification::make()->title($success)->success()->send();
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
