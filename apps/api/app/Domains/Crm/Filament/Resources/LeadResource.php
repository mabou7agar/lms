<?php

namespace App\Domains\Crm\Filament\Resources;

use App\Domains\Crm\Actions\Lead\ConvertLeadAction;
use App\Domains\Crm\Actions\Lead\MoveLeadStageAction;
use App\Domains\Crm\Enums\LeadStatus;
use App\Domains\Crm\Filament\Resources\LeadResource\Pages;
use App\Domains\Crm\Models\Lead;
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

class LeadResource extends Resource
{
    protected static ?string $model = Lead::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-user-plus';

    protected static string|\UnitEnum|null $navigationGroup = 'CRM';

    protected static ?string $recordRouteKeyName = 'public_id';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required(),
            TextInput::make('email')->email(),
            TextInput::make('company_name')->label('Company'),
            Select::make('status')->options(collect(LeadStatus::cases())->mapWithKeys(fn ($s) => [$s->value => ucfirst($s->value)])->all()),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->searchable(),
            TextColumn::make('company_name')->label('Company')->searchable()->toggleable(),
            TextColumn::make('stage.name')->label('Stage')->badge()->toggleable(),
            TextColumn::make('owner.name')->label('Owner')->toggleable()->placeholder('Unassigned'),
            TextColumn::make('status')->badge(),
            TextColumn::make('request_type')->badge()->toggleable(),
            TextColumn::make('lead_score')->label('Score')->sortable()->numeric()->toggleable(),
            TextColumn::make('value_minor')->label('Value')
                ->formatStateUsing(fn ($state, Lead $record): string => $state === null ? '—' : number_format(((int) $state) / 100, 2).' '.($record->currency ?? 'USD'))
                ->toggleable(),
            TextColumn::make('source')->toggleable(),
            TextColumn::make('next_follow_up_at')->label('Follow up')->dateTime()->toggleable(),
        ])
            ->recordActions([self::moveStageAction(), self::convertAction()])
            ->defaultSort('id', 'desc');
    }

    /** Move a lead through the pipeline. Delegates to MoveLeadStageAction (no persistence here). */
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
            ->action(function (array $data, Lead $record): void {
                try {
                    $stage = Stage::where('public_id', $data['stage'])->firstOrFail();
                    app(MoveLeadStageAction::class)->execute($record, $stage);
                    Notification::make()->title("Moved to stage: {$stage->name}.")->success()->send();
                } catch (Throwable $e) {
                    Notification::make()->title('Could not move stage.')->body($e->getMessage())->danger()->send();
                }
            });
    }

    /** Convert a lead into a contact. Delegates to ConvertLeadAction. */
    private static function convertAction(): Action
    {
        return Action::make('convert')
            ->label('Convert')
            ->icon('heroicon-o-user-group')
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading('Convert lead')
            ->modalDescription('Create a contact from this lead and mark it converted. This cannot be undone.')
            ->visible(fn (Lead $record): bool => ! $record->isConverted())
            ->action(function (Lead $record): void {
                try {
                    app(ConvertLeadAction::class)->execute($record);
                    Notification::make()->title('Lead converted to a contact.')->success()->send();
                } catch (Throwable $e) {
                    Notification::make()->title('Could not convert lead.')->body($e->getMessage())->danger()->send();
                }
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLead::route('/'),
            'create' => Pages\CreateLead::route('/create'),
            'edit' => Pages\EditLead::route('/{record}/edit'),
        ];
    }
}
