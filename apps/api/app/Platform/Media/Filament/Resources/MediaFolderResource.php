<?php

namespace App\Platform\Media\Filament\Resources;

use App\Platform\Identity\Contracts\Actor;
use App\Platform\Media\Filament\Resources\MediaFolderResource\Pages;
use App\Platform\Media\Models\MediaFolder;
use App\Platform\Media\Services\MediaFolderService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use UnitEnum;

/**
 * Phase 8 / D1 - Folders/collections management for the DAM. A thin CRUD surface over MediaFolder;
 * every mutation with data-safety implications is delegated to MediaFolderService (create/rename/move
 * are transactional and cycle-guarded; DELETE reassigns assets to root and reparents children BEFORE
 * removing the folder, so deleting a folder never deletes its assets). Policy-gated by
 * MediaFolderPolicy (admin/super_admin operator + per-record ownership).
 *
 * Auto-discovered by AdminPanelProvider (it already scans Platform/Media/Filament/Resources), so no
 * composition-root change is required for it to appear under the existing "Media" nav group.
 */
class MediaFolderResource extends Resource
{
    protected static ?string $model = MediaFolder::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-folder';

    protected static string|UnitEnum|null $navigationGroup = 'Media';

    protected static ?string $navigationLabel = 'Media Folders';

    protected static ?string $recordRouteKeyName = 'public_id';

    protected static ?string $recordTitleAttribute = 'name';

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
        return self::canAccess() && self::operatorCan('create', MediaFolder::class);
    }

    public static function canEdit(Model $record): bool
    {
        return self::operatorCan('update', $record);
    }

    public static function canDelete(Model $record): bool
    {
        return self::operatorCan('delete', $record);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(255),
            Select::make('parent_id')
                ->label('Parent folder')
                ->options(fn (?MediaFolder $record): array => self::parentOptions($record))
                ->searchable()
                ->placeholder('— Root —'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->withCount('assets'))
            ->columns([
                TextColumn::make('name')->label('Folder')->searchable()->sortable(),
                TextColumn::make('parent.name')->label('Parent')->default('— Root —')->toggleable(),
                TextColumn::make('assets_count')->label('Assets')->badge()->sortable(),
                TextColumn::make('created_by')->label('Owner (user id)')->toggleable(),
                TextColumn::make('created_at')->dateTime()->sortable()->toggleable(),
            ])
            ->recordActions([
                EditAction::make(),
                self::deleteAction(),
            ])
            ->defaultSort('name');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMediaFolders::route('/'),
            'create' => Pages\CreateMediaFolder::route('/create'),
            'edit' => Pages\EditMediaFolder::route('/{record}/edit'),
        ];
    }

    /**
     * Delete that keeps assets: delegated to MediaFolderService, which reassigns this folder's assets
     * to root and reparents its children before removing the row. The confirmation makes the
     * "assets are preserved" contract explicit.
     */
    public static function deleteAction(): Action
    {
        return Action::make('deleteFolder')
            ->label('Delete')
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Delete folder')
            ->modalDescription('The folder is removed but its media assets are kept and moved to the root. Subfolders move up to this folder\'s parent. Continue?')
            ->visible(fn (MediaFolder $record): bool => self::canDelete($record))
            ->action(function (MediaFolder $record): void {
                $user = Auth::user();

                if (! $user instanceof Actor) {
                    Notification::make()->title('Not authorized')->danger()->send();

                    return;
                }

                app(MediaFolderService::class)->delete($record, $user->actorId());
                Notification::make()->title('Folder deleted; assets preserved')->success()->send();
            });
    }

    /**
     * Parent options exclude the record itself (a folder cannot be its own parent). Descendant cycles
     * are additionally guarded by MediaFolderService::move at save time.
     *
     * @return array<int, string>
     */
    private static function parentOptions(?MediaFolder $record): array
    {
        return MediaFolder::query()
            ->when($record !== null, fn (Builder $q): Builder => $q->whereKeyNot($record->getKey()))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    private static function operatorCan(string $ability, Model|string $arg): bool
    {
        $user = Auth::user();

        return $user instanceof Actor && Gate::forUser($user)->allows($ability, $arg);
    }
}
