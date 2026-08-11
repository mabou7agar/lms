<?php

namespace App\Domains\Crm\Filament\Resources;

use App\Domains\Crm\Actions\Task\CompleteTaskAction;
use App\Domains\Crm\Enums\CrmTaskType;
use App\Domains\Crm\Filament\Resources\TaskResource\Pages;
use App\Domains\Crm\Models\CrmTask;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Throwable;

class TaskResource extends Resource
{
    protected static ?string $model = CrmTask::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-check-circle';

    protected static string|\UnitEnum|null $navigationGroup = 'CRM';

    protected static ?string $recordRouteKeyName = 'public_id';

    protected static ?string $modelLabel = 'task';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')->required(),
            Select::make('type')->options(collect(CrmTaskType::cases())->mapWithKeys(fn ($t) => [$t->value => ucfirst(str_replace('_', ' ', $t->value))])->all()),
            TextInput::make('priority')->maxLength(16),
            DateTimePicker::make('due_at'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('title')->searchable(),
            TextColumn::make('type')->badge(),
            TextColumn::make('status')->badge(),
            TextColumn::make('priority')->toggleable(),
            TextColumn::make('due_at')->dateTime()->sortable()->toggleable(),
            IconColumn::make('completed_at')->label('Done')->boolean()->toggleable(),
        ])
            ->recordActions([self::completeAction()])
            ->defaultSort('id', 'desc');
    }

    /** Mark a task done. Delegates to CompleteTaskAction. */
    private static function completeAction(): Action
    {
        return Action::make('complete')
            ->label('Complete')
            ->icon('heroicon-o-check')
            ->color('success')
            ->visible(fn (CrmTask $record): bool => ! $record->isComplete())
            ->action(function (CrmTask $record): void {
                try {
                    app(CompleteTaskAction::class)->execute($record);
                    Notification::make()->title('Task completed.')->success()->send();
                } catch (Throwable $e) {
                    Notification::make()->title('Could not complete task.')->body($e->getMessage())->danger()->send();
                }
            });
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListTask::route('/')];
    }
}
