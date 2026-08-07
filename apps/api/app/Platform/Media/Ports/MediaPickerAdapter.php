<?php

namespace App\Platform\Media\Ports;

use App\Platform\Media\Models\MediaAsset;
use App\Platform\Media\Services\MediaAdminUploadService;
use App\Platform\Media\Services\MediaPickerAssetValidator;
use App\Platform\Shared\Media\Contracts\MediaPickerPort;
use App\Platform\Shared\Media\Contracts\PlaybackPort;
use App\Platform\Shared\Media\Enums\MediaPurpose;
use App\Platform\Shared\Media\Enums\MediaType;
use App\Platform\Shared\Media\Exceptions\MediaUnavailableException;

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
        $asset = MediaAsset::query()->where('public_id', $publicId)->first();

        if ($asset === null || ! $asset->status->isPlayable()) {
            return null;
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

        return (string) $asset->public_id;
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
