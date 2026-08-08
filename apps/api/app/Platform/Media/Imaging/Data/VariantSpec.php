<?php

namespace App\Platform\Media\Imaging\Data;

/**
 * Phase A / D6 - An immutable description of ONE variant to derive: its key within a surface set, the
 * target box, the fit mode and the output format/quality. Built by VariantPlanner from config, then
 * consumed deterministically by ImageProcessor::render — same spec + same input bytes => same result.
 */
final readonly class VariantSpec
{
    /**
     * @param  'fit'|'cover'  $mode  'fit' contains within the box (aspect kept, never upscaled);
     *                                'cover' fills the box then centre-crops to EXACT width x height.
     * @param  'webp'|'jpeg'|'png'|'avif'  $format
     */
    public function __construct(
        public string $key,
        public int $width,
        public int $height,
        public string $mode,
        public string $format,
        public int $quality,
    ) {}

    /**
     * @param  array<string, mixed>  $definition  a single entry from config('media.images.variant_sets')
     * @param  array<string, int>  $qualityDefaults  config('media.images.quality')
     */
    public static function fromConfig(string $key, array $definition, array $qualityDefaults): self
    {
        $format = (string) ($definition['format'] ?? 'webp');

        return new self(
            key: $key,
            width: (int) ($definition['width'] ?? 0),
            height: (int) ($definition['height'] ?? 0),
            mode: ($definition['mode'] ?? 'fit') === 'cover' ? 'cover' : 'fit',
            format: $format,
            quality: (int) ($definition['quality'] ?? ($qualityDefaults[$format] ?? 82)),
        );
    }
}
