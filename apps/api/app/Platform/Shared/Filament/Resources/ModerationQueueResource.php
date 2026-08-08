<?php

namespace App\Platform\Shared\Filament\Resources;

use App\Platform\Shared\Filament\Resources\ModerationQueueResource\Pages;
use App\Platform\Shared\Moderation\Enums\ReportReason;
use App\Platform\Shared\Moderation\Enums\ReportStatus;
use App\Platform\Shared\Moderation\Models\ContentReport;
use App\Platform\Shared\Moderation\Services\ModerationService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Read + triage view over the shared content-report queue. Admin-only. Reports are raised by the
 * owning domains through the CanBeReported trait; this resource never creates or edits a report's
 * substance — the only writes are moderator resolutions, delegated wholesale to ModerationService.
 *
 * This class lives in the Shared layer, which (per Deptrac) may depend on nothing internal — so it
 * NEVER imports the Identity Actor contract. It reaches the authenticated principal only through the
 * Auth/Filament facades and duck-types the Spatie hasRole() method, exactly like the Shared MediaPicker.
 */
class ModerationQueueResource extends Resource
{
    protected static ?string $model = ContentReport::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-flag';

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?string $navigationLabel = 'Moderation Queue';

    protected static ?string $recordRouteKeyName = 'public_id';

    public static function canViewAny(): bool
    {
        return self::userCanModerate();
    }

    public static function canView(Model $record): bool
    {
        return self::userCanModerate();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    /** Only a super_admin or admin may work the moderation queue. */
    public static function userCanModerate(): bool
    {
        $user = auth()->user() ?? Filament::auth()->user();

        if ($user === null || ! method_exists($user, 'hasRole')) {
            return false;
        }

        return $user->hasRole('super_admin') || $user->hasRole('admin');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->label('Reported')->dateTime()->sortable(),
                TextColumn::make('reportable_type')->label('Type')->badge()->toggleable(),
                TextColumn::make('reportable_id')->label('Target')->toggleable(),
                TextColumn::make('reason')->badge()->sortable(),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('reporter_user_id')->label('Reporter')->toggleable(),
                TextColumn::make('resolved_by')->label('Resolved by')->toggleable(),
                TextColumn::make('resolved_at')->dateTime()->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(collect(ReportStatus::cases())
                    ->mapWithKeys(fn (ReportStatus $s): array => [$s->value => $s->label()])
                    ->all()),
                SelectFilter::make('reason')->options(collect(ReportReason::cases())
                    ->mapWithKeys(fn (ReportReason $r): array => [$r->value => $r->label()])
                    ->all()),
            ])
            ->recordActions([
                self::resolveAction('markReviewed', 'Mark reviewed', 'heroicon-o-check', 'success',
                    fn (ModerationService $svc, ContentReport $r, int $uid) => $svc->resolve($r, $uid)),
                self::resolveAction('dismiss', 'Dismiss', 'heroicon-o-x-mark', 'gray',
                    fn (ModerationService $svc, ContentReport $r, int $uid) => $svc->dismiss($r, $uid)),
                self::resolveAction('action', 'Mark actioned', 'heroicon-o-shield-exclamation', 'danger',
                    fn (ModerationService $svc, ContentReport $r, int $uid) => $svc->action($r, $uid)),
            ])
            ->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListModerationQueue::route('/')];
    }

    /**
     * Build a moderator transition button. Visible only on an open (pending) report and only for a
     * moderator; the actual state change is delegated to ModerationService.
     *
     * @param  callable(ModerationService, ContentReport, int): ContentReport  $apply
     */
    private static function resolveAction(string $name, string $label, string $icon, string $color, callable $apply): Action
    {
        return Action::make($name)
            ->label($label)
            ->icon($icon)
            ->color($color)
            ->requiresConfirmation()
            ->visible(fn (ContentReport $record): bool => self::userCanModerate() && $record->isOpen())
            ->action(function (ContentReport $record) use ($apply): void {
                $moderatorId = auth()->id() ?? Filament::auth()->id();

                if ($moderatorId === null) {
                    Notification::make()->title('Not authorized')->danger()->send();

                    return;
                }

                $apply(app(ModerationService::class), $record, (int) $moderatorId);

                Notification::make()->title('Report updated')->success()->send();
            });
    }
}
