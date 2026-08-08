<?php

namespace App\Platform\Media\Imaging;

use App\Platform\Media\Exceptions\ImageProcessingException;
use App\Platform\Media\Exceptions\ImageRejectedException;
use App\Platform\Media\Imaging\Data\ProcessedVariant;
use App\Platform\Media\Imaging\Data\VariantSpec;
use GdImage;

/**
 * Phase A / D6 - The thin, native ext-gd image engine. It is deliberately dependency-free (no composer
 * package, no vendor call) and does exactly six things, in this order, with the original bytes never
 * mutated:
 *
 *   1. Server-side format verification from MAGIC BYTES (not the extension or a client-sent mime).
 *   2. Decompression-bomb defence: dimensions/pixel-count/byte-size are read from the image HEADER
 *      (getimagesizefromstring) and rejected BEFORE any full decode, so a tiny-file-huge-canvas image
 *      is refused without ever allocating its raster.
 *   3. EXIF orientation normalisation (JPEG) so a rotated capture renders upright.
 *   4. Deterministic resize / cover-crop into a fresh true-colour canvas (imagecopyresampled).
 *   5. Re-encode to webp/jpeg/png (avif only where GD supports it) at a pinned quality — which, as a
 *      side effect, STRIPS all EXIF/metadata (GD emits none), giving privacy-safe derived variants.
 *   6. Return the encoded bytes; persistence is the caller's job.
 *
 * Determinism: for a given runtime (libgd/libwebp build) the same input bytes + same VariantSpec yield
 * byte-identical output, and always identical DIMENSIONS. The pipeline is pure — no randomness, no time.
 */
class ImageProcessor
{
    /** @param array<string, mixed> $config config('media.images') */
    public function __construct(private readonly array $config) {}

    // ---- Verification + bomb guard -------------------------------------------------------------

    /**
     * Identify the image format purely from magic bytes. Returns 'jpeg'|'png'|'gif'|'webp' or null for
     * anything that is not one of those (a non-image, or a spoofed extension). Never trusts a mime hint.
     */
    public function detectFormat(string $bytes): ?string
    {
        $len = strlen($bytes);

        if ($len >= 3 && substr($bytes, 0, 3) === "\xFF\xD8\xFF") {
            return 'jpeg';
        }
        if ($len >= 8 && substr($bytes, 0, 8) === "\x89PNG\r\n\x1a\n") {
            return 'png';
        }
        if ($len >= 6 && (substr($bytes, 0, 6) === 'GIF87a' || substr($bytes, 0, 6) === 'GIF89a')) {
            return 'gif';
        }
        // RIFF....WEBP
        if ($len >= 12 && substr($bytes, 0, 4) === 'RIFF' && substr($bytes, 8, 4) === 'WEBP') {
            return 'webp';
        }

        return null;
    }

    /** Assert the bytes are a supported input image (by magic bytes) and return the format. */
    public function assertSupportedImage(string $bytes): string
    {
        $format = $this->detectFormat($bytes);

        if ($format === null || ! in_array($format, $this->acceptedInputFormats(), true)) {
            throw new ImageRejectedException('The file is not a supported image (magic-byte verification failed).');
        }

        return $format;
    }

    /**
     * Read width/height from the HEADER only — no raster is allocated. Returns [width, height, mime].
     *
     * @return array{width: int, height: int, mime: string}
     */
    public function probe(string $bytes): array
    {
        $info = @getimagesizefromstring($bytes);

        if ($info === false) {
            throw new ImageRejectedException('The image header could not be read.');
        }

        return [
            'width' => (int) ($info[0] ?? 0),
            'height' => (int) ($info[1] ?? 0),
            'mime' => (string) ($info['mime'] ?? ''),
        ];
    }

    /**
     * The decompression-bomb + size gate. Runs BEFORE any full decode: magic-byte format check, on-disk
     * byte cap, then header dimensions vs the configured max width/height and the max pixel budget
     * (width * height). Throws ImageRejectedException (permanent) on any breach.
     *
     * @return array{width: int, height: int, mime: string, format: string}
     */
    public function assertWithinLimits(string $bytes): array
    {
        $format = $this->assertSupportedImage($bytes);

        $limits = $this->limits();
        $byteLen = strlen($bytes);

        if ($byteLen > $limits['max_bytes']) {
            throw new ImageRejectedException('The image exceeds the maximum allowed file size.', [
                'size_bytes' => $byteLen,
                'max_bytes' => $limits['max_bytes'],
            ]);
        }

        $probe = $this->probe($bytes);
        $width = $probe['width'];
        $height = $probe['height'];

        if ($width < 1 || $height < 1) {
            throw new ImageRejectedException('The image reports non-positive dimensions.');
        }

        if ($width > $limits['max_width'] || $height > $limits['max_height']) {
            throw new ImageRejectedException('The image exceeds the maximum allowed dimensions.', [
                'width' => $width,
                'height' => $height,
                'max_width' => $limits['max_width'],
                'max_height' => $limits['max_height'],
            ]);
        }

        // The primary decompression-bomb defence: refuse an oversized pixel budget before decoding.
        if ($width * $height > $limits['max_pixels']) {
            throw new ImageRejectedException('The image pixel count exceeds the safe decode budget (possible decompression bomb).', [
                'pixels' => $width * $height,
                'max_pixels' => $limits['max_pixels'],
            ]);
        }

        return $probe + ['format' => $format];
    }

    // ---- Rendering -----------------------------------------------------------------------------

    /**
     * Verify + guard, decode, orientation-normalise, resize/crop, and re-encode $bytes into one variant.
     * The input bytes are treated as read-only; the returned ProcessedVariant carries fresh encoded bytes.
     *
     * @throws ImageRejectedException on a permanent input rejection (mime/bomb/oversize).
     * @throws ImageProcessingException on a GD decode/encode failure or an unsupported encoder.
     */
    public function render(string $bytes, VariantSpec $spec): ProcessedVariant
    {
        // Re-run the guard so render() is safe to call standalone (the service also guards up-front; the
        // header probe is cheap and idempotent, so double-checking never costs a decode).
        $probe = $this->assertWithinLimits($bytes);

        $source = @imagecreatefromstring($bytes);
        if (! $source instanceof GdImage) {
            throw new ImageProcessingException('GD failed to decode the source image.');
        }

        try {
            $source = $this->normalizeOrientation($source, (string) $probe['format'], $bytes);
            $canvas = $this->transform($source, $spec);

            $out = $this->encode($canvas, $spec);
            $width = imagesx($canvas);
            $height = imagesy($canvas);

            if ($canvas !== $source) {
                imagedestroy($canvas);
            }

            return new ProcessedVariant($spec->key, $width, $height, $spec->format, $out);
        } finally {
            imagedestroy($source);
        }
    }

    // ---- Orientation ---------------------------------------------------------------------------

    /**
     * Normalise EXIF orientation for JPEG sources so the raster is upright, then subsequent encoding
     * (which carries no EXIF) leaves the variant correctly oriented with metadata stripped. Non-JPEG or
     * runtimes without ext-exif are returned unchanged (orientation 1). Returns the (possibly new) image;
     * the original handle is destroyed when a rotation/flip replaces it.
     */
    private function normalizeOrientation(GdImage $image, string $format, string $bytes): GdImage
    {
        if ($format !== 'jpeg' || ! function_exists('exif_read_data')) {
            return $image;
        }

        $orientation = 1;

        try {
            $exif = @exif_read_data('data://image/jpeg;base64,'.base64_encode($bytes));
            if (is_array($exif) && isset($exif['Orientation'])) {
                $orientation = (int) $exif['Orientation'];
            }
        } catch (\Throwable) {
            $orientation = 1;
        }

        return $this->applyOrientation($image, $orientation);
    }

    /**
     * Apply one of the 8 EXIF orientation transforms. Public so the transform matrix can be unit-tested
     * without crafting an EXIF fixture. Orientation 1 (or unknown) is a no-op. Values 5-8 imply a 90/270
     * rotation, so the returned image has swapped width/height.
     */
    public function applyOrientation(GdImage $image, int $orientation): GdImage
    {
        $flip = static function (GdImage $img, int $mode): void {
            if (function_exists('imageflip')) {
                imageflip($img, $mode);
            }
        };

        switch ($orientation) {
            case 2:
                $flip($image, IMG_FLIP_HORIZONTAL);

                return $image;
            case 3:
                return $this->replace($image, imagerotate($image, 180, 0));
            case 4:
                $flip($image, IMG_FLIP_VERTICAL);

                return $image;
            case 5:
                $flip($image, IMG_FLIP_HORIZONTAL);

                return $this->replace($image, imagerotate($image, 270, 0));
            case 6:
                return $this->replace($image, imagerotate($image, 270, 0));
            case 7:
                $flip($image, IMG_FLIP_HORIZONTAL);

                return $this->replace($image, imagerotate($image, 90, 0));
            case 8:
                return $this->replace($image, imagerotate($image, 90, 0));
            default:
                return $image;
        }
    }

    /** Swap $old for $rotated (imagerotate result), destroying $old. Falls back to $old on failure. */
    private function replace(GdImage $old, GdImage|false $rotated): GdImage
    {
        if (! $rotated instanceof GdImage) {
            return $old;
        }

        imagealphablending($rotated, false);
        imagesavealpha($rotated, true);
        imagedestroy($old);

        return $rotated;
    }

    // ---- Geometry ------------------------------------------------------------------------------

    /**
     * Resize (mode 'fit', aspect kept, NEVER upscaled — the max-dimension clamp) or cover-crop (mode
     * 'cover', fill the box then centre-crop to EXACT width x height) $source into a fresh canvas.
     */
    private function transform(GdImage $source, VariantSpec $spec): GdImage
    {
        $sw = imagesx($source);
        $sh = imagesy($source);
        $tw = max(1, $spec->width);
        $th = max(1, $spec->height);

        if ($spec->mode === 'cover') {
            // Choose the largest centred source sub-rectangle with the target aspect ratio, then resample
            // it to exactly (tw x th). This crops the overflow rather than distorting.
            $targetRatio = $tw / $th;
            $srcRatio = $sw / $sh;

            if ($srcRatio > $targetRatio) {
                $cropH = $sh;
                $cropW = (int) round($sh * $targetRatio);
            } else {
                $cropW = $sw;
                $cropH = (int) round($sw / $targetRatio);
            }

            $cropW = max(1, min($cropW, $sw));
            $cropH = max(1, min($cropH, $sh));
            $srcX = (int) max(0, round(($sw - $cropW) / 2));
            $srcY = (int) max(0, round(($sh - $cropH) / 2));

            $canvas = $this->blankCanvas($tw, $th, $spec->format);
            imagecopyresampled($canvas, $source, 0, 0, $srcX, $srcY, $tw, $th, $cropW, $cropH);

            return $canvas;
        }

        // 'fit': scale down to fit within the box, keep aspect, and clamp at 1.0 so we never upscale.
        $scale = min($tw / $sw, $th / $sh, 1.0);
        $dw = max(1, (int) round($sw * $scale));
        $dh = max(1, (int) round($sh * $scale));

        $canvas = $this->blankCanvas($dw, $dh, $spec->format);
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $dw, $dh, $sw, $sh);

        return $canvas;
    }

    /**
     * A fresh true-colour canvas. For formats WITHOUT an alpha channel (jpeg) it is flood-filled white so
     * a transparent source flattens predictably; for alpha-capable formats (webp/png/gif) it is filled
     * fully transparent and alpha is preserved on save.
     */
    private function blankCanvas(int $width, int $height, string $format): GdImage
    {
        $canvas = imagecreatetruecolor($width, $height);

        if (in_array($format, ['jpeg', 'jpg'], true)) {
            $white = imagecolorallocate($canvas, 255, 255, 255);
            imagefilledrectangle($canvas, 0, 0, $width, $height, $white);

            return $canvas;
        }

        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
        imagefilledrectangle($canvas, 0, 0, $width, $height, $transparent);

        return $canvas;
    }

    // ---- Encoding ------------------------------------------------------------------------------

    /**
     * Re-encode $canvas to the spec's format at a pinned quality, capturing the bytes via an output
     * buffer. GD writes NO EXIF/metadata, so the derived variant is metadata-stripped by construction.
     */
    private function encode(GdImage $canvas, VariantSpec $spec): string
    {
        ob_start();

        try {
            switch ($spec->format) {
                case 'webp':
                    if (! $this->supportsWebp()) {
                        throw new ImageProcessingException('This runtime\'s GD build cannot encode WebP.');
                    }
                    imagewebp($canvas, null, $spec->quality);
                    break;

                case 'jpeg':
                case 'jpg':
                    imagejpeg($canvas, null, $spec->quality);
                    break;

                case 'png':
                    imagepng($canvas, null, $this->pngLevel());
                    break;

                case 'avif':
                    if (! $this->supportsAvif()) {
                        throw new ImageProcessingException('This runtime\'s GD build cannot encode AVIF.');
                    }
                    imageavif($canvas, null, $spec->quality);
                    break;

                default:
                    throw new ImageProcessingException("Unsupported output format: {$spec->format}.");
            }
        } catch (ImageProcessingException $e) {
            ob_end_clean();
            throw $e;
        }

        $out = (string) ob_get_clean();

        if ($out === '') {
            throw new ImageProcessingException('GD produced empty output for the variant.');
        }

        return $out;
    }

    // ---- Capability probes ---------------------------------------------------------------------

    public function supportsWebp(): bool
    {
        return function_exists('imagewebp') && (imagetypes() & IMG_WEBP) === IMG_WEBP;
    }

    /** AVIF is emitted only when the runtime's GD was compiled with AVIF support; otherwise skipped. */
    public function supportsAvif(): bool
    {
        return function_exists('imageavif')
            && defined('IMG_AVIF')
            && (imagetypes() & IMG_AVIF) === IMG_AVIF;
    }

    // ---- Config helpers ------------------------------------------------------------------------

    /** @return list<string> */
    private function acceptedInputFormats(): array
    {
        /** @var list<string> $formats */
        $formats = (array) ($this->config['accepted_input_formats'] ?? ['jpeg', 'png', 'gif', 'webp']);

        return $formats;
    }

    /** @return array{max_bytes: int, max_width: int, max_height: int, max_pixels: int} */
    private function limits(): array
    {
        $limits = (array) ($this->config['limits'] ?? []);

        return [
            'max_bytes' => (int) ($limits['max_bytes'] ?? 25 * 1024 * 1024),
            'max_width' => (int) ($limits['max_width'] ?? 12000),
            'max_height' => (int) ($limits['max_height'] ?? 12000),
            'max_pixels' => (int) ($limits['max_pixels'] ?? 40_000_000),
        ];
    }

    private function pngLevel(): int
    {
        $level = (int) ($this->config['png_level'] ?? 6);

        return max(0, min(9, $level));
    }
}
