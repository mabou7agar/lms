<?php

namespace App\Domains\Catalog\Filament\Resources\CourseResource\RelationManagers;

use App\Domains\Catalog\Models\Course;
use App\Domains\Catalog\Models\CourseTrainer;
use App\Domains\Catalog\Services\CourseInstructorService;
use App\Platform\Identity\Contracts\Actor;
use App\Platform\Identity\Contracts\UserLookupPort;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

/**
 * U4 - Multi-instructor assignment for a course. Every mutation is routed through
 * CourseInstructorService so the pivot's composite key, the at-most-one-primary invariant, and the
 * permission-safe authorization all hold — the default model-writing Create/Edit actions are
 * deliberately NOT used. Instructor names are resolved from user ids through the Identity
 * UserLookupPort (this manager imports no User model).
 */
class InstructorsRelationManager extends RelationManager
{
    protected static string $relationship = 'trainerLinks';

    protected static ?string $title = 'Instructors';

    /** Unused (no default create/edit actions) — mutations go through the service below. */
    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('user_id')
            ->defaultSort('position')
            ->columns([
                TextColumn::make('position')->label('#')->sortable(),
                TextColumn::make('user_id')->label('Instructor')
                    ->formatStateUsing(fn ($state): string => app(UserLookupPort::class)
                        ->refById((int) $state)?->name ?? ('#'.$state)),
                TextColumn::make('role')->label('Role')->placeholder('—'),
                IconColumn::make('is_primary')->boolean()->label('Primary'),
            ])
            ->headerActions([
                Action::make('attach')
                    ->label('Attach instructor')
                    ->icon('heroicon-o-user-plus')
                    ->schema([
                        Select::make('instructor')
                            ->label('Instructor')
                            ->required()
                            ->searchable()
                            ->options(fn (): array => collect(app(UserLookupPort::class)->instructors())
                                ->mapWithKeys(fn ($ref): array => [$ref->publicId => $ref->name])
                                ->all()),
                        TextInput::make('role')->label('Role')->maxLength(255),
                        Toggle::make('is_primary')->label('Primary instructor'),
                    ])
                    ->action(function (array $data, InstructorsRelationManager $livewire): void {
                        $ref = app(UserLookupPort::class)->refByPublicId((string) $data['instructor']);

                        if ($ref === null) {
                            return;
                        }

                        app(CourseInstructorService::class)->assign(
                            course: $livewire->ownerCourse(),
                            instructorId: $ref->id,
                            actor: $livewire->actor(),
                            role: $data['role'] ?? null,
                            isPrimary: (bool) ($data['is_primary'] ?? false),
                        );
                    }),
            ])
            ->recordActions([
                Action::make('makePrimary')
                    ->label('Make primary')
                    ->icon('heroicon-o-star')
                    ->visible(fn (CourseTrainer $record): bool => ! $record->is_primary)
                    ->action(fn (CourseTrainer $record, InstructorsRelationManager $livewire): mixed => app(CourseInstructorService::class)
                        ->setPrimary($livewire->ownerCourse(), (int) $record->user_id, $livewire->actor())),
                Action::make('detach')
                    ->label('Detach')
                    ->icon('heroicon-o-x-mark')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(fn (CourseTrainer $record, InstructorsRelationManager $livewire): mixed => app(CourseInstructorService::class)
                        ->unassign($livewire->ownerCourse(), (int) $record->user_id, $livewire->actor())),
            ])
            ->toolbarActions([]);
    }

    public function ownerCourse(): Course
    {
        /** @var Course $course */
        $course = $this->getOwnerRecord();

        return $course;
    }

    public function actor(): Actor
    {
        /** @var Actor $actor */
        $actor = Auth::user();

        return $actor;
    }
}
