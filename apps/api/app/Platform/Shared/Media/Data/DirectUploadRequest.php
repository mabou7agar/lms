<?php

namespace App\Platform\Shared\Media\Data;

use App\Platform\Shared\Media\Enums\MediaPurpose;
use App\Platform\Shared\Media\Enums\MediaType;

/**
 * P2/W04 - A validated request for a signed upload slot. Type/size are already bounded by the
 * purpose before this DTO is built, so an adapter can trust them.
 */
final readonly class DirectUploadRequest
{
    public function __construct(
        public string $mediaPublicId,
        public MediaType $type,
        public MediaPurpose $purpose,
        public string $filename,
        public string $mimeType,
        public int $sizeBytes,
        public int $actorId,
        public ?int $courseId,
        public string $idempotencyKey,
    ) {}
}
