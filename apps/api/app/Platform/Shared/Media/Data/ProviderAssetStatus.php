<?php

namespace App\Platform\Shared\Media\Data;

use App\Platform\Shared\Media\Enums\MediaStatus;

/**
 * P2/W04 - Authoritative asset state read back from the provider (on verify or webhook). Playback
 * ids/dimensions/duration are populated only once the provider reports readiness. Raw provider
 * asset ids stay in $providerAssetRef and are never exposed to clients.
 */
final readonly class ProviderAssetStatus
{
    public function __construct(
        public MediaStatus $status,
        public ?string $providerAssetRef = null,
        public ?string $playbackId = null,
        public ?string $storageKey = null,
        public ?string $mimeType = null,
        public ?int $sizeBytes = null,
        public ?int $durationSeconds = null,
        public ?int $width = null,
        public ?int $height = null,
        public ?string $failureCode = null,
        public ?string $failureMessage = null,
    ) {}
}
