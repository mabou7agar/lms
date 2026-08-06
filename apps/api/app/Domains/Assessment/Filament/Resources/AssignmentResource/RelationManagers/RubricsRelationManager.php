<?php

namespace App\Domains\Assessment\Filament\Resources\AssignmentResource\RelationManagers;

use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Rubric authoring for an assignment. The form binds directly to AssignmentRubric and its nested
 * `criteria` -> `levels` HasMany relations, so persistence and the `{field}_i18n` -> legacy scalar
 * sync run through the models — the Filament layer performs no persistence of its own.
 *
 * The deterministic point roll-ups are computed with the same rule the engine's
 * AssignmentService::buildRubric applies (a criterion's max_points is the highest of its levels; the
 * rubric total is the sum of those maxima), but as `dehydrateStateUsing` transforms rather than a
 * persisted write, so a total can never drift from the levels. The active-rubric pointer
 * (`assignments.rubric_id`) is set on the assignment form's "Active rubric" select. Ordering of
 * criteria and levels is the `position` column (repeater order column).
 */
class RubricsRelationManager extends RelationManager
{
    protected static string $relationship = 'rubrics';

    protected static ?string $title = 'Rubric';

    protected static ?string $recordTitleAttribute = 'title';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(2)->schema([
                TextInput::make('title_i18n.en')->label('Rubric title (EN)')->maxLength(255),
                TextInput::make('title_i18n.ar')->label('Rubric title (AR)')->maxLength(255)
                    ->extraInputAttributes(['dir' => 'rtl']),
            ]),
            Hidden::make('total_points')
                ->dehydrateStateUsing(fn (Get $get): float => self::totalPoints($get('criteria'))),
            Repeater::make('criteria')->label('Criteria')
                ->relationship()
                ->orderColumn('position')
                ->schema([
                    Grid::make(2)->schema([
                        TextInput::make('title_i18n.en')->label('Criterion (EN)')->required(),
                        TextInput::make('title_i18n.ar')->label('Criterion (AR)')
                            ->extraInputAttributes(['dir' => 'rtl']),
                        Textarea::make('description_i18n.en')->label('Description (EN)')->rows(2),
                        Textarea::make('description_i18n.ar')->label('Description (AR)')->rows(2)
                            ->extraInputAttributes(['dir' => 'rtl']),
                    ]),
                    Hidden::make('max_points')
                        ->dehydrateStateUsing(fn (Get $get): float => self::maxPoints($get('levels'))),
                    Repeater::make('levels')->label('Levels')
                        ->relationship()
                        ->orderColumn('position')
                        ->schema([
                            Grid::make(2)->schema([
                                TextInput::make('title_i18n.en')->label('Level (EN)')->required(),
                                TextInput::make('title_i18n.ar')->label('Level (AR)')
                                    ->extraInputAttributes(['dir' => 'rtl']),
                                Textarea::make('description_i18n.en')->label('Description (EN)')->rows(2),
                                Textarea::make('description_i18n.ar')->label('Description (AR)')->rows(2)
                                    ->extraInputAttributes(['dir' => 'rtl']),
                            ]),
                            TextInput::make('points')->label('Points')->numeric()->default(0)->minValue(0)->required(),
                        ])
                        ->reorderable()->collapsible()->defaultItems(1)->addActionLabel('Add level'),
                ])
                ->reorderable()->collapsible()->defaultItems(1)->addActionLabel('Add criterion'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->columns([
                TextColumn::make('title_i18n.en')->label('Rubric')->placeholder('—')->wrap(),
                TextColumn::make('criteria_count')->counts('criteria')->label('Criteria'),
                TextColumn::make('total_points')->label('Total points'),
            ])
            ->headerActions([
                CreateAction::make()->modalHeading('Build rubric'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([]);
    }

    /**
     * Sum of each criterion's maximum level points — the engine's rubric total, computed on
     * dehydrate from the live form state.
     */
    private static function totalPoints(mixed $criteria): float
    {
        $items = is_array($criteria) ? array_filter($criteria, 'is_array') : [];

        $total = 0.0;

        foreach ($items as $criterion) {
            $total += self::maxPoints($criterion['levels'] ?? null);
        }

        return $total;
    }

    /** The highest points among a criterion's levels. */
    private static function maxPoints(mixed $levels): float
    {
        $items = is_array($levels) ? array_filter($levels, 'is_array') : [];

        $max = 0.0;

        foreach ($items as $level) {
            $max = max($max, (float) ($level['points'] ?? 0));
        }

        return $max;
    }
}
