<?php

namespace App\Domains\Assessment\Filament\Resources\AssessmentResource\RelationManagers;

use App\Domains\Assessment\Actions\Question\DuplicateQuestionAction;
use App\Domains\Assessment\Enums\Difficulty;
use App\Domains\Assessment\Enums\QuestionType;
use App\Domains\Assessment\Models\AssessmentQuestion;
use App\Domains\Assessment\Services\QuestionShapeGuard;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Question authoring for an assessment. The form binds directly to AssessmentQuestion and its
 * `options` HasMany, so persistence, the per-locale HtmlSanitizer (prompt / explanation / hint are
 * declared translatableHtml) and the `{field}_i18n` -> legacy scalar sync all run through the model
 * exactly as they do for the API — the Filament layer performs no persistence of its own.
 *
 * Structural correctness is still the engine's: the options repeater carries a validation rule that
 * calls the domain QuestionShapeGuard (single-choice with two correct answers, an empty label, a
 * text question with no accepted answer, …), so an ungradable question is rejected before it can be
 * saved. The correct-answer control is the per-option `is_correct` toggle; question and option order
 * is the `position` column (drag-reorderable table / repeater order column).
 */
class QuestionsRelationManager extends RelationManager
{
    protected static string $relationship = 'questions';

    protected static ?string $title = 'Questions';

    protected static ?string $recordTitleAttribute = 'prompt';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('type')->label('Type')
                ->options(self::questionTypeOptions())
                ->default(QuestionType::SingleChoice->value)->required()->live()
                ->helperText('Choice types are graded from the option toggles; short-answer / fill-in-the-blank match the learner\'s text against each option\'s accepted value.'),
            Grid::make(2)->schema([
                RichEditor::make('prompt_i18n.en')->label('Prompt (EN)')->required()
                    ->helperText('HTML is sanitized on save.'),
                RichEditor::make('prompt_i18n.ar')->label('Prompt (AR)')
                    ->extraInputAttributes(['dir' => 'rtl']),
                RichEditor::make('explanation_i18n.en')->label('Explanation (EN)')
                    ->helperText('Revealed per the assessment\'s feedback mode.'),
                RichEditor::make('explanation_i18n.ar')->label('Explanation (AR)')
                    ->extraInputAttributes(['dir' => 'rtl']),
                RichEditor::make('hint_i18n.en')->label('Hint (EN)'),
                RichEditor::make('hint_i18n.ar')->label('Hint (AR)')
                    ->extraInputAttributes(['dir' => 'rtl']),
            ]),
            Grid::make(3)->schema([
                TextInput::make('points')->label('Points')->numeric()->default(1)->minValue(0)->required(),
                TextInput::make('negative_points')->label('Negative points')->numeric()->default(0)->minValue(0)
                    ->helperText('Positive magnitude; the scorer applies the sign when negative marking is on.'),
                Select::make('difficulty')->label('Difficulty')
                    ->options(self::enumOptions(Difficulty::cases())),
            ]),
            // Type-specific `config` keys — ONLY the keys a grader for the current type actually
            // reads (see ShortAnswer/FillInBlank/MultipleChoice graders). Each toggle is both hidden
            // AND non-dehydrated when it does not apply, so an unsupported key is never written.
            Section::make('Type-specific settings')
                ->description('Grading options for the selected question type.')
                ->columns(3)
                ->visible(fn (Get $get): bool => self::usesTextMatching($get) || self::supportsPartialCredit($get))
                ->schema([
                    Toggle::make('config.case_sensitive')->label('Case sensitive')->inline(false)->default(false)
                        ->helperText('Match the learner\'s text exactly, including letter case.')
                        ->visible(fn (Get $get): bool => self::usesTextMatching($get))
                        ->dehydrated(fn (Get $get): bool => self::usesTextMatching($get)),
                    Toggle::make('config.normalize_arabic')->label('Normalize Arabic')->inline(false)->default(true)
                        ->helperText('Forgive Arabic orthography and Arabic-Indic digits when matching.')
                        ->visible(fn (Get $get): bool => self::usesTextMatching($get))
                        ->dehydrated(fn (Get $get): bool => self::usesTextMatching($get)),
                    Toggle::make('config.partial_credit')->label('Partial credit')->inline(false)->default(false)
                        ->helperText('Award a fraction of the marks for a partially correct answer.')
                        ->visible(fn (Get $get): bool => self::supportsPartialCredit($get))
                        ->dehydrated(fn (Get $get): bool => self::supportsPartialCredit($get)),
                ]),
            Repeater::make('options')->label('Options / accepted answers')
                ->relationship()
                ->orderColumn('position')
                ->schema([
                    Grid::make(2)->schema([
                        TextInput::make('label_i18n.en')->label('Label (EN)'),
                        TextInput::make('label_i18n.ar')->label('Label (AR)')
                            ->extraInputAttributes(['dir' => 'rtl']),
                    ]),
                    TextInput::make('value')->label('Accepted answer / machine value')
                        ->helperText('Used by short-answer and fill-in-the-blank. Optional for choice types.'),
                    Grid::make(2)->schema([
                        TextInput::make('feedback_i18n.en')->label('Feedback (EN)'),
                        TextInput::make('feedback_i18n.ar')->label('Feedback (AR)')
                            ->extraInputAttributes(['dir' => 'rtl']),
                    ]),
                    Grid::make(2)->schema([
                        Toggle::make('is_correct')->label('Correct answer')->inline(false),
                        TextInput::make('group_index')->label('Blank # (fill-in-the-blank)')->numeric()->default(0),
                    ]),
                ])
                ->reorderable()
                ->collapsible()
                ->defaultItems(2)
                ->addActionLabel('Add option')
                ->rule(fn (Get $get): Closure => self::shapeRule($get)),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('prompt')
            ->defaultSort('position')
            ->reorderable('position')
            ->columns([
                TextColumn::make('position')->label('#')->sortable(),
                TextColumn::make('prompt_i18n.en')->label('Prompt')
                    ->formatStateUsing(fn ($state): string => Str::limit(strip_tags((string) $state), 70))
                    ->wrap(),
                TextColumn::make('type')->badge()
                    ->formatStateUsing(fn ($state): string => $state instanceof QuestionType ? $state->label() : (string) $state),
                TextColumn::make('options_count')->counts('options')->label('Options'),
                TextColumn::make('points')->label('Points'),
            ])
            ->headerActions([
                CreateAction::make()
                    ->modalHeading('Add question')
                    // Append to the end; explicit reordering is the drag handle on the table.
                    ->mutateDataUsing(fn (array $data, RelationManager $livewire): array => [
                        ...$data,
                        'position' => (int) $livewire->getOwnerRecord()->questions()->max('position') + 1,
                    ]),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('duplicate')
                    ->label('Duplicate')
                    ->icon('heroicon-o-document-duplicate')
                    ->color('gray')
                    // Deep-copies the question and its options (fresh ids, config carried) and appends
                    // the copy to the end of the assessment. Carries no attempt data.
                    ->action(function (AssessmentQuestion $record): void {
                        app(DuplicateQuestionAction::class)->execute($record);

                        Notification::make()->title('Question duplicated')->success()->send();
                    }),
                DeleteAction::make(),
            ])
            ->toolbarActions([]);
    }

    /**
     * A closure validation rule that runs the domain QuestionShapeGuard against the current option
     * set and question type, so an ungradable configuration is rejected at authoring time exactly as
     * the API rejects it.
     */
    private static function shapeRule(Get $get): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($get): void {
            $type = is_string($get('type')) ? QuestionType::tryFrom($get('type')) : null;

            if ($type === null) {
                return;
            }

            try {
                app(QuestionShapeGuard::class)->assertValid($type, self::guardShape($value));
            } catch (ValidationException $exception) {
                $fail($exception->validator->errors()->first() ?: 'The options are not valid for this question type.');
            }
        };
    }

    /**
     * Flatten repeater option items into the shape QuestionShapeGuard expects. The visible label is
     * taken from the EN locale (the guard checks that every choice option carries visible text).
     *
     * @return array<int, array<string, mixed>>
     */
    private static function guardShape(mixed $value): array
    {
        $items = is_array($value) ? array_filter($value, 'is_array') : [];

        return array_values(array_map(static function (array $option): array {
            $labels = is_array($option['label_i18n'] ?? null) ? $option['label_i18n'] : [];

            return [
                'label' => is_string($labels['en'] ?? null) ? $labels['en'] : null,
                'value' => $option['value'] ?? null,
                'is_correct' => (bool) ($option['is_correct'] ?? false),
                'group_index' => (int) ($option['group_index'] ?? 0),
            ];
        }, $items));
    }

    /** The currently-selected question type, or null when nothing valid is chosen. */
    private static function selectedType(Get $get): ?QuestionType
    {
        return is_string($get('type')) ? QuestionType::tryFrom($get('type')) : null;
    }

    /** Whether the current type is graded by matching learner text (short answer / fill-in-blank). */
    private static function usesTextMatching(Get $get): bool
    {
        return self::selectedType($get)?->usesTextMatching() ?? false;
    }

    /** Whether a grader for the current type honours the `partial_credit` config key. */
    private static function supportsPartialCredit(Get $get): bool
    {
        $type = self::selectedType($get);

        return $type === QuestionType::MultipleChoice || $type === QuestionType::FillInBlank;
    }

    /**
     * @return array<string, string>
     */
    private static function questionTypeOptions(): array
    {
        $options = [];

        foreach (QuestionType::cases() as $type) {
            $options[$type->value] = $type->label();
        }

        return $options;
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
