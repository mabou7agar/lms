<?php

use App\Platform\Media\Exceptions\ImageProcessingException;
use App\Platform\Media\Exceptions\ImageRejectedException;
use App\Platform\Media\Imaging\Data\VariantSpec;
use App\Platform\Media\Imaging\ImageProcessor;

/*
 | D6 - Native ext-gd image engine. These exercise the real GD codec, so they run on the user's
 | GD-enabled PHP (the sandbox that authored them has no PHP). Fixtures are tiny in-memory rasters.
 */

beforeEach(function () {
    if (! function_exists('imagecreatetruecolor')) {
        $this->markTestSkipped('ext-gd is required for the image pipeline tests.');
    }
});

/** A processor with a known config (independent of the merged global config). */
function d6Processor(array $overrides = []): ImageProcessor
{
    $base = [
        'accepted_input_formats' => ['jpeg', 'png', 'gif', 'webp'],
        'limits' => [
            'max_bytes' => 25 * 1024 * 1024,
            'max_width' => 12000,
            'max_height' => 12000,
            'max_pixels' => 40_000_000,
        ],
        'quality' => ['webp' => 82, 'jpeg' => 82, 'avif' => 50],
        'png_level' => 6,
    ];

    return new ImageProcessor(array_replace_recursive($base, $overrides));
}

/** A tiny solid-colour raster encoded in $format. */
function d6MakeImage(int $width, int $height, string $format = 'png'): string
{
    $img = imagecreatetruecolor($width, $height);
    $colour = imagecolorallocate($img, 130, 70, 40);
    imagefilledrectangle($img, 0, 0, $width, $height, $colour);

    ob_start();
    match ($format) {
        'jpeg' => imagejpeg($img, null, 90),
        'gif' => imagegif($img),
        'webp' => imagewebp($img, null, 90),
        default => imagepng($img),
    };
    $bytes = (string) ob_get_clean();
    imagedestroy($img);

    return $bytes;
}

/**
 * A HEADER-ONLY PNG that merely CLAIMS huge dimensions in its IHDR (no pixel data). getimagesizefromstring
 * reads width/height straight from the header, so the pipeline can reject a decompression bomb without
 * ever allocating the raster — which is exactly what this fixture proves.
 */
function d6PngHeaderClaiming(int $width, int $height): string
{
    $signature = "\x89PNG\r\n\x1a\n";
    $ihdr = 'IHDR'.pack('N', $width).pack('N', $height)."\x08\x06\x00\x00\x00"; // 8-bit RGBA
    $crc = pack('N', crc32($ihdr));

    return $signature.pack('N', 13).$ihdr.$crc;
}

it('rejects a non-image by magic bytes, not extension', function () {
    d6Processor()->assertWithinLimits('this is definitely not an image, even named photo.png');
})->throws(ImageRejectedException::class);

it('detects real image formats from magic bytes', function () {
    $processor = d6Processor();

    expect($processor->detectFormat(d6MakeImage(4, 4, 'png')))->toBe('png')
        ->and($processor->detectFormat(d6MakeImage(4, 4, 'jpeg')))->toBe('jpeg')
        ->and($processor->detectFormat(d6MakeImage(4, 4, 'gif')))->toBe('gif')
        ->and($processor->detectFormat('plain text'))->toBeNull();
});

it('reads header dimensions without decoding (decompression-bomb probe)', function () {
    // The fixture is only a header; a full decode would fail, but the probe reads the claimed size.
    $probe = d6Processor()->probe(d6PngHeaderClaiming(60000, 60000));

    expect($probe['width'])->toBe(60000)
        ->and($probe['height'])->toBe(60000);
});

it('rejects a decompression bomb from the header before any decode', function () {
    // 60000 x 60000 = 3.6e9 pixels, far above the 40 MP budget. Rejected on the header alone.
    d6Processor()->assertWithinLimits(d6PngHeaderClaiming(60000, 60000));
})->throws(ImageRejectedException::class);

it('rejects an over-pixel image against a tight pixel budget', function () {
    $processor = d6Processor(['limits' => ['max_pixels' => 100]]);

    // 200 x 100 = 20 000 pixels > 100.
    $processor->assertWithinLimits(d6MakeImage(200, 100, 'png'));
})->throws(ImageRejectedException::class);

it('rejects an oversize file against a tight byte budget', function () {
    $processor = d6Processor(['limits' => ['max_bytes' => 32]]);

    $processor->assertWithinLimits(d6MakeImage(200, 100, 'png'));
})->throws(ImageRejectedException::class);

it('rejects an over-dimension image against a tight dimension budget', function () {
    $processor = d6Processor(['limits' => ['max_width' => 50]]);

    $processor->assertWithinLimits(d6MakeImage(200, 100, 'png'));
})->throws(ImageRejectedException::class);

it('normalizes EXIF orientation, swapping dimensions for a 90/270 rotation', function () {
    $processor = d6Processor();
    $img = imagecreatetruecolor(40, 20);

    // Orientation 6 implies a 270° rotation on save; a 40x20 becomes 20x40.
    $rotated = $processor->applyOrientation($img, 6);

    expect(imagesx($rotated))->toBe(20)
        ->and(imagesy($rotated))->toBe(40);

    imagedestroy($rotated);
});

it('resizes with a max-dimension clamp (fit mode never upscales)', function () {
    $processor = d6Processor();
    $spec = new VariantSpec('medium', 50, 50, 'fit', 'png', 82);

    // 200x100 fit into 50x50 => scaled by 0.25 => 50x25 (aspect kept, not upscaled).
    $variant = $processor->render(d6MakeImage(200, 100, 'png'), $spec);

    expect($variant->width)->toBe(50)
        ->and($variant->height)->toBe(25);
});

it('does not upscale a source smaller than the fit box', function () {
    $processor = d6Processor();
    $spec = new VariantSpec('large', 500, 500, 'fit', 'png', 82);

    $variant = $processor->render(d6MakeImage(80, 60, 'png'), $spec);

    // Clamp at scale 1.0 — the source is returned at its own size, never enlarged.
    expect($variant->width)->toBe(80)
        ->and($variant->height)->toBe(60);
});

it('crops to exact thumbnail dimensions (cover mode)', function () {
    $processor = d6Processor();
    $spec = new VariantSpec('thumbnail', 50, 50, 'cover', 'png', 82);

    $variant = $processor->render(d6MakeImage(200, 100, 'png'), $spec);

    expect($variant->width)->toBe(50)
        ->and($variant->height)->toBe(50);
});

it('produces a WebP variant when the runtime supports it', function () {
    $processor = d6Processor();

    if (! $processor->supportsWebp()) {
        $this->markTestSkipped('This GD build cannot encode WebP.');
    }

    $spec = new VariantSpec('small', 40, 40, 'cover', 'webp', 80);
    $variant = $processor->render(d6MakeImage(120, 90, 'png'), $spec);

    expect($variant->format)->toBe('webp')
        ->and($processor->detectFormat($variant->bytes))->toBe('webp')
        ->and($variant->width)->toBe(40)
        ->and($variant->height)->toBe(40);
});

it('strips EXIF/metadata on the derived variant', function () {
    $processor = d6Processor();
    $spec = new VariantSpec('medium', 60, 60, 'fit', 'jpeg', 82);

    $variant = $processor->render(d6MakeImage(120, 120, 'jpeg'), $spec);

    // GD emits no EXIF; the re-encoded variant carries no APP1/"Exif" marker.
    expect(str_contains($variant->bytes, 'Exif'))->toBeFalse();

    if (function_exists('exif_read_data')) {
        $exif = @exif_read_data('data://image/jpeg;base64,'.base64_encode($variant->bytes));
        expect($exif === false || ! isset($exif['Orientation']))->toBeTrue();
    }
});

it('is deterministic: same input + params yields identical bytes and dimensions', function () {
    $processor = d6Processor();
    $spec = new VariantSpec('medium', 64, 48, 'fit', 'png', 82);
    $source = d6MakeImage(200, 150, 'png');

    $a = $processor->render($source, $spec);
    $b = $processor->render($source, $spec);

    expect($a->bytes)->toBe($b->bytes)
        ->and($a->width)->toBe($b->width)
        ->and($a->height)->toBe($b->height);
});

it('raises a processing (not rejection) error on undecodable but well-typed bytes', function () {
    // Valid PNG magic + truncated body: passes the magic-byte check and the header probe, then GD fails
    // the full decode — a transient ImageProcessingException, distinct from a permanent rejection.
    $processor = d6Processor();
    $spec = new VariantSpec('medium', 32, 32, 'fit', 'png', 82);

    $broken = d6PngHeaderClaiming(10, 10); // header only, no IDAT
    $processor->render($broken, $spec);
})->throws(ImageProcessingException::class);
