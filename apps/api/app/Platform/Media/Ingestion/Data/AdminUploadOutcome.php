<?php

namespace App\Platform\Media\Ingestion\Data;

use App\Platform\Media\Models\MediaAsset;

/**
 * Phase 8 / D4 - Per-file result of an admin (bulk) upload. A batch collects one of these per file so
 * a single bad file reports its own error without failing the rest of the batch. Media-internal only.
 */
final readonly class AdminUploadOutcome
{
    private function __construct(
        public string $filename,
        public bool $successful,
        public ?MediaAsset $asset = null,
        public ?string $errorMessage = null,
    ) {}

    public static function success(string $filename, MediaAsset $asset): self
    {
        return new self($filename, true, $asset, null);
    }

    public static function failure(string $filename, string $errorMessage): self
    {
        return new self($filename, false, null, $errorMessage);
    }
}
