<?php

namespace App\Platform\Shared\Media\Data;

use App\Platform\Shared\Media\Enums\MediaStatus;
use App\Platform\Shared\Media\Enums\MediaType;

/**
 * P2/W04 - A cross-context, client-safe descriptor of a media asset. Carries only the public id
 * and non-sensitive metadata — never a storage key or provider asset id. Consumed by Authoring
 * and Assessment; producing a signed URL still goes through the Media platform.
 */
final readonly class MediaReference
{
    public function __construct(
        public string $publicId,
        public MediaType $type,
        public MediaStatus $status,
        public int $ownerActorId,
        public ?string $originalFilename = null,
        public ?int $sizeBytes = null,
        public ?int $durationSeconds = null,
    ) {}

    public function isReady(): bool
    {
        return $this->status->isPlayable();
    }
}
