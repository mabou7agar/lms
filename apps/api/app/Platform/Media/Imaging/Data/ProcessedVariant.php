<?php

namespace App\Platform\Media\Imaging\Data;

/**
 * Phase A / D6 - The in-memory result of rendering one VariantSpec: the encoded bytes plus the final
 * dimensions and format. Carries the bytes only; ImageVariantService is responsible for persisting
 * them as a NEW storage object and recording the media_variants row.
 */
final readonly class ProcessedVariant
{
    public function __construct(
        public string $key,
        public int $width,
        public int $height,
        public string $format,
        public string $bytes,
    ) {}

    public function sizeBytes(): int
    {
        return strlen($this->bytes);
    }
}
