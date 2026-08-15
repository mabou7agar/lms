<?php

namespace App\Platform\Media\Ports;

use App\Platform\Media\Models\MediaAsset;
use App\Platform\Media\Services\MediaAdminUploadService;
use App\Platform\Media\Services\MediaPickerAssetValidator;
use App\Platform\Media\Services\MediaVisibilityService;
use App\Platform\Shared\Media\Contracts\MediaPickerPort;
use App\Platform\Shared\Media\Contracts\PlaybackPort;
use App\Platform\Shared\Media\Enums\MediaProvider;
use App\Platform\Shared\Media\Enums\MediaPurpose;
use App\Platform\Shared\Media\Enums\MediaType;
use App\Platform\Shared\Media\Exceptions\MediaUnavailableException;
use App\Platform\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\Storage;

/**
 * Media's implementation of the Shared MediaPickerPort — the seam the reusable Filament MediaPicker
 * (a Shared component) uses to reach the Media platform. Media may depend on Shared, so this adapter
 * is where every Media-side concern is concentrated: searching the actor's selectable assets, signing
 * a short-lived preview URL, re-authorizing a picked id, and performing a server-side upload.
 *
 * Types/purposes cross the port boundary as backed-enum ->value STRINGS; this adapter maps them to
 * the concrete MediaType / MediaPurpose enums here, so neither the Shared component nor any consuming
 * domain imports a media enum. Authorization is delegated to MediaPickerAssetValidator (the trust
 * boundary) and readiness/signing to the existing MediaAssetRefResolver + PlaybackPort; nothing about
 * that behaviour changes.
 */
class MediaPickerAdapter implements MediaPickerPort
{
    public function __construct(
        private readonly MediaPickerAssetValidator $validator,
        private readonly MediaAdminUploadService $uploads,
        private readonly MediaAssetRefResolver $refs,
        private readonly PlaybackPort $playback,
        private readonly TenantContext $tenant,
        private readonly MediaVisibilityService $visibility,
    ) {}

    public function searchAssets(int $actorId, array $acceptedTypes, ?string $search): array
    {
        $types = self::mapTypes($acceptedTypes);

        $query = MediaAsset::query()
            ->where('created_by', $actorId)
            ->where('status', 'ready');

        if ($types !== []) {
            $query->whereIn('type', array_map(static fn (MediaType $t): string => $t->value, $types));
        }

        if ($search !== null && trim($search) !== '') {
            $term = '%'.trim($search).'%';
            $query->where(function ($q) use ($term): void {
                $q->where('original_filename', 'like', $term)->orWhere('public_id', 'like', $term);
            });
        }

        return $query
            ->orderByDesc('id')
            ->limit(50)
            ->get(['public_id', 'original_filename'])
            ->mapWithKeys(fn (MediaAsset $asset): array => [
                (string) $asset->public_id => $asset->original_filename ?? (string) $asset->public_id,
            ])
            ->all();
    }

    public function previewUrl(string $publicId): ?string
    {
        // Rides MediaAsset's SharedOrOwnedTenantScope: a cross-tenant public_id resolves to null under a
        // resolved tenant. The explicit tenant guard additionally denies an org-owned asset to any caller
        // that is not its owning tenant (belt-and-suspenders for a no-tenant context).
        $asset = MediaAsset::query()->where('public_id', $publicId)->first();

        if ($asset === null || ! $asset->status->isPlayable() || ! $this->visibleToActiveTenant($asset)) {
            return null;
        }

        // Dev local store: objects are publicly served off the local disk, so there is nothing to sign —
        // hand back the plain disk URL. (The playback signer targets Mux/S3 and has no local backend.)
        if ($asset->provider === MediaProvider::Local && $asset->storage_key !== null) {
            return Storage::disk((string) config('media.local.disk', 'media_local'))->url($asset->storage_key);
        }

        try {
            $token = $this->playback->issue(
                $this->refs->refForAsset($asset),
                (int) config('media.playback.ttl_seconds', 600),
            );
        } catch (MediaUnavailableException) {
            return null;
        }

        return $token->url;
    }

    public function assertSelectable(
        string $publicId,
        int $actorId,
        array $acceptedTypes,
        ?string $purpose,
        ?int $ownerScope,
    ): void {
        $this->validator->validate(
            $publicId,
            $actorId,
            self::mapTypes($acceptedTypes),
            self::mapPurpose($purpose),
            $ownerScope,
        );
    }

    public function isSelectable(
        string $publicId,
        int $actorId,
        array $acceptedTypes,
        ?string $purpose,
        ?int $ownerScope,
    ): bool {
        return $this->validator->passes(
            $publicId,
            $actorId,
            self::mapTypes($acceptedTypes),
            self::mapPurpose($purpose),
            $ownerScope,
        );
    }

    public function upload(
        int $actorId,
        string $purpose,
        string $filename,
        string $mimeType,
        int $sizeBytes,
        string $contents,
    ): string {
        $asset = $this->uploads->upload(
            actorId: $actorId,
            purpose: MediaPurpose::from($purpose),
            filename: $filename,
            mimeType: $mimeType,
            sizeBytes: $sizeBytes,
            contents: $contents,
        );

        // The picker is an admin surface for public-facing display fields (avatars, thumbnails, logos).
        // Publish freshly-uploaded IMAGES so the public URL resolver can serve them; the service is
        // owner-only + image-only and no-ops for non-images, so this never widens a non-display asset.
        $asset = $this->visibility->markPublicForOwner($asset, $actorId);

        return (string) $asset->public_id;
    }

    /** A global asset is previewable by anyone; an org-owned asset only by its owning tenant. */
    private function visibleToActiveTenant(MediaAsset $asset): bool
    {
        if ($asset->organization_id === null) {
            return true;
        }

        $tenantId = $this->tenant->id();

        return $tenantId !== null && $asset->belongsToTenant($tenantId);
    }

    /**
     * @param  list<string>  $types
     * @return list<MediaType>
     */
    private static function mapTypes(array $types): array
    {
        return array_values(array_filter(array_map(
            static fn (string $type): ?MediaType => MediaType::tryFrom($type),
            $types,
        )));
    }

    private static function mapPurpose(?string $purpose): ?MediaPurpose
    {
        return $purpose !== null ? MediaPurpose::tryFrom($purpose) : null;
    }
}
