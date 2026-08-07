<?php

namespace App\Platform\Shared\Filament\Forms\Components;

use App\Platform\Shared\Helpers\Uuid;
use App\Platform\Shared\Media\Contracts\MediaPickerPort;
use App\Platform\Shared\Media\Contracts\MediaReferencePort;
use App\Platform\Shared\Media\Data\MediaReference;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Illuminate\Support\Facades\Auth;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * Phase 6 / U1 - Reusable Filament media picker (Shared layer).
 *
 * A single form field that lets an admin EITHER pick an existing MediaAsset OR upload a new one, with
 * a signed-URL preview, search, type/purpose filtering, remove, and replace-selected-reference. It is
 * the shared foundation that ~8 upload-first admin forms wire in, so its PUBLIC API is stable and
 * documented below.
 *
 * ── ARCHITECTURE ────────────────────────────────────────────────────────────────────────────────
 * This component lives in the Shared kernel so any domain/context/platform capability may depend on
 * it. It therefore imports NO Media class: every Media-side concern (search selectable assets,
 * authorize a picked id, sign a preview URL, upload a new asset) is reached through the Shared
 * MediaPickerPort seam, which the Media platform binds to a concrete adapter. Non-sensitive asset
 * metadata (filename, type) is read through the existing Shared MediaReferencePort. Media "types"
 * and "purposes" cross the API as their backed-enum ->value STRINGS, so call sites never import a
 * media enum either.
 *
 * ── STORED VALUE ────────────────────────────────────────────────────────────────────────────────
 * The field stores a STRING reference, never a brittle public URL:
 *   • normally the picked asset's `public_id` (the same identity the app references assets by, via
 *     MediaReferencePort) — this is what "storesReference" means;
 *   • OR, for a transitional/legacy field, whatever URL/path string the field already held. The
 *     component displays that legacy value and lets the admin replace it with a picked asset WITHOUT
 *     silently dropping it (dual-read): until the admin picks/uploads, the legacy value is preserved.
 * `MediaPicker::classifyValue($state)` returns 'empty' | 'reference' | 'legacy' and is the single
 * source of truth for which of the two a stored value is (a valid UUID public_id ⇒ reference).
 *
 * ── PUBLIC API (fluent) ─────────────────────────────────────────────────────────────────────────
 *   ->acceptedTypes(['image', ...])          Restrict pickable/uploadable types (MediaType ->value
 *                                             strings). Empty ⇒ any.
 *   ->purpose('lesson_image')                 Bind an upload purpose (MediaPurpose ->value string);
 *                                             also constrains accepted types and is REQUIRED to
 *                                             enable the in-picker "Upload new".
 *   ->reusable(bool = true)                   Marks the reference as shared/reusable (documentational
 *                                             hint for consumers; the picker never mutates the asset).
 *   ->storesReference(bool = true)            Store the asset public_id (default). Kept explicit so
 *                                             the contract is legible at every call site.
 *   ->allowLegacyUrl(bool = true)             Enable the dual-read legacy-URL fallback (default on).
 *   ->ownerScope(int|Closure|null)            Optional owner/tenant scope (tenant-READY; NO tenancy
 *                                             logic — T1 is out of scope). Defaults to the acting user.
 *   ->searchable(bool = true)                 Enable search in the "Select existing" modal (default on).
 *
 * ── SAFETY ──────────────────────────────────────────────────────────────────────────────────────
 * A picked id is never trusted: MediaPickerPort re-checks ownership + accepted type/purpose
 * (owner-only, no existence leak) both when the modal is submitted and again as a field validation
 * rule at save. Previews are always short-lived signed URLs — never a raw storage key or provider ref.
 */
class MediaPicker extends Field
{
    protected string $view = 'shared::media-picker';

    /** @var list<string> MediaType ->value strings. */
    protected array $acceptedTypes = [];

    /** MediaPurpose ->value string, or null. */
    protected ?string $pickerPurpose = null;

    protected bool $isReusable = false;

    protected bool $storesReference = true;

    protected bool $allowLegacyUrl = true;

    protected int | Closure | null $ownerScope = null;

    protected bool | Closure $isSearchable = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registerActions([
            fn (MediaPicker $component): Action => $component->selectAction(),
            fn (MediaPicker $component): Action => $component->uploadAction(),
            fn (MediaPicker $component): Action => $component->removeAction(),
        ]);

        // Defense in depth: even though the modals validate before storing, re-validate a reference at
        // save so a tampered/foreign id can never be persisted. Empty + legacy values pass through.
        $this->rule(static function (MediaPicker $component): Closure {
            return static function (string $attribute, mixed $value, Closure $fail) use ($component): void {
                if (! is_string($value) || self::classifyValue($value) !== 'reference') {
                    return; // empty or legacy-URL — nothing to authorize here.
                }

                if (! $component->port()->isSelectable(
                    $value,
                    $component->resolveActorId() ?? 0,
                    $component->getAcceptedTypes(),
                    $component->getPurpose(),
                    $component->getOwnerScope(),
                )) {
                    $fail('The selected media is not available to you.');
                }
            };
        });
    }

    // ── Fluent configuration ────────────────────────────────────────────────────────────────────

    /** @param  array<int, BackedEnum|string>  $types  MediaType ->value strings (enums are tolerated). */
    public function acceptedTypes(array $types): static
    {
        $this->acceptedTypes = array_values(array_map(
            static fn (BackedEnum | string $type): string => $type instanceof BackedEnum ? (string) $type->value : $type,
            $types,
        ));

        return $this;
    }

    public function purpose(BackedEnum | string | null $purpose): static
    {
        $this->pickerPurpose = $purpose instanceof BackedEnum ? (string) $purpose->value : $purpose;

        return $this;
    }

    public function reusable(bool $condition = true): static
    {
        $this->isReusable = $condition;

        return $this;
    }

    public function storesReference(bool $condition = true): static
    {
        $this->storesReference = $condition;

        return $this;
    }

    public function allowLegacyUrl(bool $condition = true): static
    {
        $this->allowLegacyUrl = $condition;

        return $this;
    }

    public function ownerScope(int | Closure | null $scope): static
    {
        $this->ownerScope = $scope;

        return $this;
    }

    public function searchable(bool | Closure $condition = true): static
    {
        $this->isSearchable = $condition;

        return $this;
    }

    // ── Configuration accessors (used by the view + actions) ──────────────────────────────────────

    /** @return list<string> */
    public function getAcceptedTypes(): array
    {
        return $this->acceptedTypes;
    }

    public function getPurpose(): ?string
    {
        return $this->pickerPurpose;
    }

    public function isReusable(): bool
    {
        return $this->isReusable;
    }

    public function shouldStoreReference(): bool
    {
        return $this->storesReference;
    }

    public function allowsLegacyUrl(): bool
    {
        return $this->allowLegacyUrl;
    }

    public function getOwnerScope(): ?int
    {
        $scope = $this->evaluate($this->ownerScope);

        return $scope !== null ? (int) $scope : null;
    }

    public function isSearchable(): bool
    {
        return (bool) $this->evaluate($this->isSearchable);
    }

    // ── State classification + preview (dual-read) ────────────────────────────────────────────────

    /** empty | reference (a UUID public_id) | legacy (any other non-empty string, e.g. a URL/path). */
    public static function classifyValue(mixed $value): string
    {
        if (! is_string($value) || trim($value) === '') {
            return 'empty';
        }

        return Uuid::isValid($value) ? 'reference' : 'legacy';
    }

    public function getStateKind(): string
    {
        return self::classifyValue($this->getState());
    }

    /** The legacy URL/path currently held, or null when the state is empty/a reference. */
    public function getLegacyValue(): ?string
    {
        $state = $this->getState();

        return (is_string($state) && self::classifyValue($state) === 'legacy') ? $state : null;
    }

    /** The referenced asset descriptor (owner-agnostic lookup), or null when empty/legacy/missing. */
    public function getSelectedReference(): ?MediaReference
    {
        $state = $this->getState();

        if (! is_string($state) || self::classifyValue($state) !== 'reference') {
            return null;
        }

        return app(MediaReferencePort::class)->reference($state);
    }

    /**
     * A short-lived signed preview URL for the selected reference, the raw legacy URL for a legacy
     * value, or null. Never exposes a storage key / provider ref.
     */
    public function getPreviewUrl(): ?string
    {
        if ($this->getStateKind() === 'legacy') {
            return $this->getLegacyValue();
        }

        $state = $this->getState();

        if (! is_string($state) || self::classifyValue($state) !== 'reference') {
            return null;
        }

        return app(MediaPickerPort::class)->previewUrl($state);
    }

    /** A human label for the current selection (filename / legacy URL / placeholder). */
    public function getSelectionLabel(): ?string
    {
        return match ($this->getStateKind()) {
            'reference' => $this->getSelectedReference()?->originalFilename ?? (string) $this->getState(),
            'legacy' => $this->getLegacyValue(),
            default => null,
        };
    }

    /** How the current selection should be previewed in the view: image | video | link | none. */
    public function previewKind(): string
    {
        if ($this->getPreviewUrl() === null) {
            return 'none';
        }

        $reference = $this->getSelectedReference();

        if ($reference !== null) {
            return match ($reference->type->value) {
                'image' => 'image',
                'video' => 'video',
                default => 'link',
            };
        }

        // Legacy value: best-effort by extension, else a plain link.
        $legacy = strtolower((string) $this->getLegacyValue());

        return match (true) {
            (bool) preg_match('/\.(png|jpe?g|gif|webp|svg)(\?.*)?$/', $legacy) => 'image',
            (bool) preg_match('/\.(mp4|webm|mov|m4v)(\?.*)?$/', $legacy) => 'video',
            default => 'link',
        };
    }

    public function port(): MediaPickerPort
    {
        return app(MediaPickerPort::class);
    }

    public function resolveActorId(): ?int
    {
        $scope = $this->getOwnerScope();

        if ($scope !== null) {
            return $scope;
        }

        $id = Auth::id();

        return $id !== null ? (int) $id : null;
    }

    // ── Field actions ─────────────────────────────────────────────────────────────────────────────

    /** "Select existing" — a searchable, owner + type scoped picker over ready assets. */
    public function selectAction(): Action
    {
        return Action::make('select')
            ->label(fn (MediaPicker $component): string => $component->getStateKind() === 'empty' ? 'Select media' : 'Replace')
            ->icon('heroicon-o-photo')
            ->modalHeading('Select media')
            ->modalSubmitActionLabel('Use this media')
            ->schema(fn (MediaPicker $component): array => [
                Select::make('media_asset')
                    ->label('Media asset')
                    ->required()
                    ->searchable($component->isSearchable())
                    ->getSearchResultsUsing(fn (string $search): array => $component->searchAssets($search))
                    ->options(fn (): array => $component->searchAssets(null))
                    ->getOptionLabelUsing(fn ($value): ?string => app(MediaReferencePort::class)
                        ->reference((string) $value)?->originalFilename ?? $value),
            ])
            ->action(function (MediaPicker $component, array $data): void {
                $publicId = (string) ($data['media_asset'] ?? '');

                // Never trust the submitted id — re-authorize ownership + type before storing.
                $component->port()->assertSelectable(
                    $publicId,
                    $component->resolveActorId() ?? 0,
                    $component->getAcceptedTypes(),
                    $component->getPurpose(),
                    $component->getOwnerScope(),
                );

                $component->state($publicId);
            });
    }

    /** "Upload new" — drives the Media engine via the port, then stores the resulting ref. */
    public function uploadAction(): Action
    {
        return Action::make('upload')
            ->label('Upload new')
            ->icon('heroicon-o-arrow-up-tray')
            ->visible(fn (MediaPicker $component): bool => $component->getPurpose() !== null)
            ->modalHeading('Upload new media')
            ->modalSubmitActionLabel('Upload')
            ->schema([
                FileUpload::make('file')
                    ->label('File')
                    ->required()
                    ->storeFiles(false),
            ])
            ->action(function (MediaPicker $component, array $data): void {
                $purpose = $component->getPurpose();

                if ($purpose === null) {
                    return;
                }

                $file = $data['file'] ?? null;

                if (is_array($file)) {
                    $file = reset($file);
                }

                if (! $file instanceof TemporaryUploadedFile) {
                    return;
                }

                $publicId = $component->port()->upload(
                    actorId: $component->resolveActorId() ?? 0,
                    purpose: $purpose,
                    filename: $file->getClientOriginalName(),
                    mimeType: $file->getMimeType() ?: 'application/octet-stream',
                    sizeBytes: (int) $file->getSize(),
                    contents: (string) file_get_contents($file->getRealPath()),
                );

                $component->state($publicId);
            });
    }

    /** "Remove" — clears the reference (or the legacy value) back to empty. */
    public function removeAction(): Action
    {
        return Action::make('remove')
            ->label('Remove')
            ->icon('heroicon-o-x-mark')
            ->color('danger')
            ->visible(fn (MediaPicker $component): bool => $component->getStateKind() !== 'empty')
            ->requiresConfirmation()
            ->action(fn (MediaPicker $component): mixed => $component->state(null));
    }

    /**
     * Owner + type scoped asset options for the select modal. Ready assets only (so a preview can be
     * signed). Matches the search against filename or public_id. Delegated to the Media platform via
     * the Shared port so this component imports no Media class.
     *
     * @return array<string, string>
     */
    public function searchAssets(?string $search): array
    {
        $actorId = $this->resolveActorId();

        if ($actorId === null) {
            return [];
        }

        return $this->port()->searchAssets($actorId, $this->getAcceptedTypes(), $search);
    }
}
