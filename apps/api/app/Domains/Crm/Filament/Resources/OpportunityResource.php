<?php

namespace App\Domains\Crm\Filament\Resources;

use App\Domains\Crm\Actions\Opportunity\MoveOpportunityStageAction;
use App\Domains\Crm\Enums\OpportunityStatus;
use App\Domains\Crm\Filament\Resources\OpportunityResource\Pages;
use App\Domains\Crm\Models\Opportunity;
use App\Domains\Crm\Models\Stage;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Throwable;

class OpportunityResource extends Resource
{
    protected static ?string $model = Opportunity::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-currency-dollar';

    protected static string|\UnitEnum|null $navigationGroup = 'CRM';

    protected static ?string $recordRouteKeyName = 'public_id';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required(),
            TextInput::make('amount_minor')->label('Amount (minor units)')->numeric(),
            TextInput::make('currency')->maxLength(3),
            TextInput::make('probability')->numeric()->minValue(0)->maxValue(100),
            Select::make('status')->options(collect(OpportunityStatus::cases())->mapWithKeys(fn ($s) => [$s->value => ucfirst($s->value)])->all()),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->searchable(),
            TextColumn::make('stage.name')->label('Stage')->badge(),
            TextColumn::make('status')->badge(),
            TextColumn::make('amount_minor')->label('Amount')
                ->formatStateUsing(fn ($state, Opportunity $record): string => $state === null ? '—' : number_format(((int) $state) / 100, 2).' '.($record->currency ?? 'USD'))
                ->toggleable(),
            TextColumn::make('probability')->suffix('%')->sortable()->toggleable(),
            TextColumn::make('owner.name')->label('Owner')->toggleable()->placeholder('Unassigned'),
            TextColumn::make('expected_close_date')->date()->toggleable(),
        ])
            ->defaultGroup('stage.name')
            ->recordActions([self::moveStageAction()])
            ->defaultSort('id', 'desc');
    }

    /** Move an opportunity along the pipeline. Delegates to MoveOpportunityStageAction. */
    private static function moveStageAction(): Action
    {
        return Action::make('moveStage')
            ->label('Move stage')
            ->icon('heroicon-o-arrows-right-left')
            ->color('info')
            ->schema([
                Select::make('stage')
                    ->label('Target stage')
                    ->options(fn (): array => Stage::query()->orderBy('position')->pluck('name', 'public_id')->all())
                    ->required(),
            ])
            ->action(function (array $data, Opportunity $record): void {
                try {
                    $stage = Stage::where('public_id', $data['stage'])->firstOrFail();
                    app(MoveOpportunityStageAction::class)->execute($record, $stage);
                    Notification::make()->title("Moved to stage: {$stage->name}.")->success()->send();
                } catch (Throwable $e) {
                    Notification::make()->title('Could not move stage.')->body($e->getMessage())->danger()->send();
                }
            });
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListOpportunity::route('/')];
    }
}
