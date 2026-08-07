<?php

namespace App\Platform\Media\Filament\Resources;

use App\Platform\Identity\Contracts\Actor;
use App\Platform\Media\Exceptions\MediaInUseException;
use App\Platform\Media\Filament\Resources\MediaAssetResource\Pages;
use App\Platform\Media\Models\MediaAsset;
use App\Platform\Media\Models\MediaAttachment;
use App\Platform\Media\Ports\MediaAssetRefResolver;
use App\Platform\Media\Services\MediaDeletionService;
use App\Platform\Shared\Media\Contracts\PlaybackPort;
use App\Platform\Shared\Media\Enums\MediaProvider;
use App\Platform\Shared\Media\Enums\MediaPurpose;
use App\Platform\Shared\Media\Enums\MediaStatus;
use App\Platform\Shared\Media\Enums\MediaType;
use App\Platform\Shared\Media\Exceptions\MediaUnavailableException;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Throwable;
use UnitEnum;

/**
 * Digital Asset Manager (Sprint 0.4) — a management / list / detail / safe-delete surface over the
 * existing Media engine. It NEVER ingests: uploads flow through the existing direct-upload session +
 * webhook pipeline (MediaUploadService / MediaIngestionService), so there is no create or edit path
 * here. Every mutation is delegated: the only write is a safe delete that hands the asset wholesale
 * to MediaDeletionService (which re-checks usage under a row lock and refuses an in-use asset unless
 * explicitly forced) — the resource never deletes a row itself.
 *
 * Authorization: the panel entry is gated on the admin/super_admin roles (mirroring the panel's own
 * canAccessPanel, defence in depth); per-record view/delete are delegated to the existing
 * MediaAssetPolicy (owner or course-manager, super_admin via before()), so cross-user access is
 * denied exactly as it is through the instructor API. MediaAsset is NOT tenant-scoped (no global
 * scope / tenant_id — ownership is the scalar created_by), so there is no scope to bypass.
 *
 * Sensitive provider identifiers are never rendered raw: provider_ref is redacted to a last-4
 * fingerprint (as in RefundResource/SubscriptionResource) and storage_key is never displayed. The
 * only playable/downloadable link is a short-lived signed URL produced by the existing PlaybackPort
 * signer, which itself exposes no raw storage key.
 */
class MediaAssetResource extends Resource
{
    protected static ?string $model = MediaAsset::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-photo';

    protected static string|UnitEnum|null $navigationGroup = 'Media';

    protected static ?string $navigationLabel = 'Media Library';

    protected static ?string $recordRouteKeyName = 'public_id';

    protected static ?string $recordTitleAttribute = 'original_filename';

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

    /** Per-record read authorization is the existing Media policy (owner / course-manager / super_admin). */
    public static function canView(Model $record): bool
    {
        return self::operatorCan('view', $record);
    }

    /** The DAM never authors or edits an asset; ingestion is the existing upload pipeline's job. */
    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    /** Per-record delete authorization is the existing Media policy; the act itself is delegated. */
    public static function canDelete(Model $record): bool
    {
        return self::operatorCan('delete', $record);
    }

    public static function form(Schema $schema): Schema
    {
        // No editable form: an asset's status, provider identifiers and lifecycle are engine-owned.
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            // Bounded usage count in one aggregate subquery — the "used by" column and the
            // force-delete visibility read this loaded attribute, never a per-row query.
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->withCount('attachments'))
            ->columns([
                TextColumn::make('public_id')->label('Asset')->searchable(),
                TextColumn::make('original_filename')->label('Filename')->searchable()->wrap(),
                TextColumn::make('type')->badge()
                    ->formatStateUsing(fn ($state): string => $state instanceof MediaType ? ucfirst($state->value) : (string) $state)
                    ->toggleable(),
                TextColumn::make('status')->badge()
                    ->formatStateUsing(fn ($state): string => $state instanceof MediaStatus ? ucfirst(str_replace('_', ' ', $state->value)) : (string) $state)
                    ->color(fn ($state): string => $state instanceof MediaStatus ? self::statusColor($state) : 'gray')
                    ->sortable(),
                TextColumn::make('provider')->badge()
                    ->formatStateUsing(fn ($state): string => $state instanceof MediaProvider ? ucfirst($state->value) : (string) $state)
                    ->toggleable(),
                TextColumn::make('created_by')->label('Owner (user id)')->sortable(),
                TextColumn::make('attachments_count')->label('Used by')->badge()->sortable(),
                TextColumn::make('created_at')->dateTime()->sortable()->toggleable(),
            ])
            ->filters([
                SelectFilter::make('type')->options(self::enumOptions(MediaType::cases())),
                SelectFilter::make('status')->options(self::enumOptions(MediaStatus::cases())),
                SelectFilter::make('provider')->options(self::enumOptions(MediaProvider::cases())),
            ])
            ->recordActions([
                ViewAction::make(),
                self::deleteAction(),
                self::forceDeleteAction(),
            ])
            ->defaultSort('id', 'desc');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Asset')->columns(2)->schema([
                TextEntry::make('public_id')->label('Asset ID'),
                TextEntry::make('original_filename')->label('Filename')->default('—'),
                TextEntry::make('type')->badge()
                    ->formatStateUsing(fn ($state): string => $state instanceof MediaType ? ucfirst($state->value) : (string) $state),
                TextEntry::make('purpose')->badge()
                    ->formatStateUsing(fn ($state): string => $state instanceof MediaPurpose ? ucfirst(str_replace('_', ' ', $state->value)) : (string) $state),
                TextEntry::make('provider')->badge()
                    ->formatStateUsing(fn ($state): string => $state instanceof MediaProvider ? ucfirst($state->value) : (string) $state),
                TextEntry::make('created_by')->label('Owner (user id)'),
                TextEntry::make('mime_type')->label('MIME type')->default('—'),
                TextEntry::make('size_bytes')->label('Size (bytes)')->numeric()->default('—'),
                TextEntry::make('duration_seconds')->label('Duration (s)')->numeric()->default('—'),
                TextEntry::make('dimensions')->label('Dimensions')
                    ->state(fn (MediaAsset $record): string => $record->width !== null && $record->height !== null
                        ? sprintf('%d × %d', $record->width, $record->height)
                        : '—'),
            ]),
            Section::make('Processing')->columns(2)->schema([
                TextEntry::make('status')->badge()
                    ->formatStateUsing(fn ($state): string => $state instanceof MediaStatus ? ucfirst(str_replace('_', ' ', $state->value)) : (string) $state)
                    ->color(fn ($state): string => $state instanceof MediaStatus ? self::statusColor($state) : 'gray'),
                TextEntry::make('processing_progress')->label('Progress (%)')->numeric(),
                TextEntry::make('failure_code')->label('Failure code')->default('—'),
                TextEntry::make('failure_message')->label('Failure message')->default('—')->columnSpanFull(),
                TextEntry::make('retry_state')->label('Retry state')
                    ->state(fn (MediaAsset $record): string => $record->status->isRetryable()
                        ? 'Failed — retryable'
                        : ($record->status === MediaStatus::Failed ? 'Failed' : 'None')),
            ]),
            Section::make('Usage')->columns(2)->schema([
                TextEntry::make('usage_count')->label('Times used')
                    ->state(fn (MediaAsset $record): int => self::usageCount($record)),
                TextEntry::make('used_by')->label('Used by')
                    ->columnSpanFull()
                    ->listWithLineBreaks()
                    ->state(fn (MediaAsset $record): array => self::usageReferences($record)),
            ]),
            Section::make('Delivery & provider')->columns(2)->schema([
                // A short-lived signed URL from the existing PlaybackPort signer; only issued for a
                // READY asset and never exposing a raw storage key. Non-ready assets show a hint.
                TextEntry::make('signed_preview')->label('Signed preview / download')
                    ->state(fn (MediaAsset $record): string => self::signedPreviewUrl($record) ?? 'Available once the asset is Ready.')
                    ->url(fn (MediaAsset $record): ?string => self::signedPreviewUrl($record))
                    ->openUrlInNewTab(),
                TextEntry::make('provider_ref')->label('Provider reference (redacted)')
                    ->state(fn (MediaAsset $record): string => self::redactProviderRef($record->provider_ref)),
            ]),
            Section::make('Timeline')->columns(2)->schema([
                TextEntry::make('created_at')->label('Created')->dateTime(),
                TextEntry::make('updated_at')->label('Updated')->dateTime(),
            ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMediaAssets::route('/'),
            'view' => Pages\ViewMediaAsset::route('/{record}'),
        ];
    }

    /**
     * Safe delete — delegates wholesale to MediaDeletionService, which re-checks usage under a row
     * lock and refuses an in-use asset (MediaInUseException) unless forced. The resource never
     * deletes the row itself; a domain rejection surfaces only as a danger Notification.
     */
    public static function deleteAction(): Action
    {
        return Action::make('safeDelete')
            ->label('Delete')
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Delete media asset')
            ->modalDescription('Delete this asset and purge it from the provider? An asset that is still attached somewhere is refused — detach it first, or use "Force delete".')
            ->visible(fn (MediaAsset $record): bool => self::canDelete($record))
            ->action(function (MediaAsset $record): void {
                self::runDeletion($record, false);
            });
    }

    /**
     * Force delete — the stronger path MediaDeletionService supports: it cascades a detach of every
     * usage under the same lock before soft-deleting. Only shown for an in-use asset, behind an
     * explicitly stronger confirmation.
     */
    public static function forceDeleteAction(): Action
    {
        return Action::make('forceDelete')
            ->label('Force delete')
            ->icon('heroicon-o-exclamation-triangle')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Force delete media asset')
            ->modalDescription('This asset is IN USE. Force-deleting detaches it from every place it is used and then deletes it. This cannot be undone. Continue?')
            ->modalSubmitActionLabel('Force delete')
            ->visible(fn (MediaAsset $record): bool => self::canDelete($record) && self::loadedUsageCount($record) > 0)
            ->action(function (MediaAsset $record): void {
                self::runDeletion($record, true);
            });
    }

    /**
     * @param  array<int, BackedEnum>  $cases
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

    private static function statusColor(MediaStatus $status): string
    {
        return match ($status) {
            MediaStatus::Ready => 'success',
            MediaStatus::Failed => 'danger',
            MediaStatus::Processing, MediaStatus::Uploading, MediaStatus::Uploaded => 'warning',
            MediaStatus::Deleted, MediaStatus::Cancelled => 'gray',
            default => 'info',
        };
    }

    /** Whether the signed-in operator satisfies the given Media policy ability for this record. */
    private static function operatorCan(string $ability, Model $record): bool
    {
        $user = Auth::user();

        return $user instanceof Actor && Gate::forUser($user)->allows($ability, $record);
    }

    /** Drive a delete through MediaDeletionService, surfacing its guards as notifications. */
    private static function runDeletion(MediaAsset $record, bool $force): void
    {
        $user = Auth::user();

        if (! $user instanceof Actor) {
            Notification::make()->title('Not authorized')->danger()->send();

            return;
        }

        try {
            app(MediaDeletionService::class)->deleteAsset($record, $user->actorId(), $force);
            Notification::make()->title('Media deleted')->success()->send();
        } catch (MediaInUseException $e) {
            Notification::make()->title('Cannot delete media')->body($e->getMessage())->danger()->send();
        } catch (Throwable $e) {
            Notification::make()->title('Delete failed')->body($e->getMessage())->danger()->send();
        }
    }

    /** Live usage count (single aggregate query). Used on the single-record detail page. */
    private static function usageCount(MediaAsset $asset): int
    {
        return MediaAttachment::query()->where('media_asset_id', $asset->getKey())->count();
    }

    /** Prefer the count already loaded on the list row; fall back to a query off the list. */
    private static function loadedUsageCount(MediaAsset $asset): int
    {
        $count = $asset->getAttribute('attachments_count');

        return $count !== null ? (int) $count : self::usageCount($asset);
    }

    /** @return array<int, string> "used by" references (attachable type/id + role), or a placeholder. */
    private static function usageReferences(MediaAsset $asset): array
    {
        $rows = MediaAttachment::query()
            ->where('media_asset_id', $asset->getKey())
            ->orderBy('id')
            ->get(['attachable_type', 'attachable_id', 'role']);

        if ($rows->isEmpty()) {
            return ['Not attached anywhere.'];
        }

        return $rows
            ->map(fn (MediaAttachment $a): string => sprintf('%s #%d (%s)', $a->attachable_type, $a->attachable_id, $a->role))
            ->all();
    }

    /**
     * Short-lived signed URL via the existing PlaybackPort signer, or null when the asset is not
     * READY / cannot be signed. The signer exposes no raw storage key or provider ref. Public so the
     * preview/redaction guarantee can be asserted at the resource boundary.
     */
    public static function signedPreviewUrl(MediaAsset $asset): ?string
    {
        if (! $asset->status->isPlayable()) {
            return null;
        }

        try {
            $token = app(PlaybackPort::class)->issue(
                app(MediaAssetRefResolver::class)->refForAsset($asset),
                (int) config('learning.playback.ttl_seconds', 600),
            );
        } catch (MediaUnavailableException) {
            return null;
        }

        return $token->url;
    }

    /** Redact a provider reference to a last-4 fingerprint; never surface the raw value. */
    public static function redactProviderRef(?string $reference): string
    {
        if ($reference === null || $reference === '') {
            return '—';
        }

        $tail = substr($reference, -4);

        return str_repeat('•', 4).($tail !== '' ? ' '.$tail : '');
    }
}
