<?php

namespace App\Contexts\Learning\Filament\Resources;

use App\Contexts\Learning\Filament\Resources\CourseCompletionPolicyResource\Pages;
use App\Contexts\Learning\Models\CourseCompletionPolicy;
use App\Platform\Identity\Contracts\Actor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Admin surface for per-course completion policies. A course with NO row keeps the platform default
 * ("100% of published lessons"), so this resource only ever surfaces the courses an admin has chosen
 * to configure. course_id is the primary key and is fixed once created; the toggles below layer extra
 * gates on top of (or, by disabling the lesson rule, replace) the default.
 *
 * The Course and Final-exam selects read straight from the tables via the query builder — never an
 * Authoring/Assessment model — so this Learning-layer resource stays within Shared + Identity bounds.
 */
class CourseCompletionPolicyResource extends Resource
{
    protected static ?string $model = CourseCompletionPolicy::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-check-badge';

    protected static string|\UnitEnum|null $navigationGroup = 'Learning';

    protected static ?string $navigationLabel = 'Completion Policies';

    /** The panel is admin-only (User::canAccessPanel); mirror it here as defence in depth. */
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
            Section::make('Course')->columns(1)->schema([
                Select::make('course_id')->label('Course')
                    ->options(fn (): array => DB::table('courses')->orderBy('title')->pluck('title', 'id')->all())
                    ->searchable()->required()
                    // The policy is keyed by course; changing it on an existing row would re-target it.
                    ->disabled(fn (?CourseCompletionPolicy $record): bool => $record !== null)
                    ->dehydrated()
                    ->helperText('One policy per course. A course with no policy uses the default: 100% of published lessons.'),
            ]),
            Section::make('Rules')->columns(2)->schema([
                Toggle::make('require_all_lessons')->label('Require all lessons')->inline(false)->default(true)
                    ->helperText('The default rule: every published lesson must be complete. Disable to complete on the other gates alone.'),
                TextInput::make('min_watch_percentage')->label('Minimum watch %')->numeric()->minValue(0)->maxValue(100)
                    ->helperText('Leave empty to disable. Aggregate watched vs total video duration must reach this.'),
                Toggle::make('require_required_quizzes')->label('Require required quizzes')->inline(false)
                    ->helperText('Every course assessment flagged "required for completion" must be passed.'),
                Toggle::make('require_required_assignments')->label('Require required assignments')->inline(false)
                    ->helperText('Every required assignment in the course must be satisfied.'),
                Toggle::make('require_final_exam')->label('Require final exam')->inline(false)
                    ->helperText('Gate on the specific assessment named below being passed.'),
                Select::make('final_exam_assessment_id')->label('Final exam')
                    ->options(fn (): array => DB::table('assessments')->orderBy('title')->pluck('title', 'id')->all())
                    ->searchable()
                    ->helperText('The assessment the learner must pass when "Require final exam" is on. Leave empty and that gate does nothing.'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('course_id')->label('Course')
                    ->formatStateUsing(fn ($state): string => (string) (DB::table('courses')->where('id', $state)->value('title') ?? $state))
                    ->searchable(),
                IconColumn::make('require_all_lessons')->boolean()->label('Lessons'),
                TextColumn::make('min_watch_percentage')->label('Watch %')->placeholder('—'),
                IconColumn::make('require_required_quizzes')->boolean()->label('Quizzes'),
                IconColumn::make('require_final_exam')->boolean()->label('Final exam'),
                IconColumn::make('require_required_assignments')->boolean()->label('Assignments'),
                TextColumn::make('updated_at')->dateTime()->since()->label('Updated')->toggleable(),
            ])
            ->defaultSort('updated_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCourseCompletionPolicies::route('/'),
            'create' => Pages\CreateCourseCompletionPolicy::route('/create'),
            'edit' => Pages\EditCourseCompletionPolicy::route('/{record}/edit'),
        ];
    }
}
