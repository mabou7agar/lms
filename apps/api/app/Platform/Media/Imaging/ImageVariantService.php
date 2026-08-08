<?php

namespace App\Platform\Media\Imaging;

use App\Platform\Media\Exceptions\ImageProcessingException;
use App\Platform\Media\Imaging\Data\ProcessedVariant;
use App\Platform\Media\Imaging\Data\VariantSpec;
use App\Platform\Media\Models\MediaAsset;
use App\Platform\Media\Models\MediaVariant;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/**
 * Phase A / D6 - Orchestrates variant generation over the SAME filesystem abstraction the ingestion
 * adapters use (Storage::disk), so it works untouched against Storage::fake() in tests and a real S3
 * disk in production. For a Ready image asset it:
 *
 *   1. Fetches the ORIGINAL bytes from the asset's storage_key (read-only).
 *   2. Runs the decompression-bomb + mime guard ONCE, up-front, before any decode.
 *   3. Plans the variant set for the surface, renders each with ImageProcessor, and writes each as a
 *      NEW object under a deterministic per-asset key — the original object is never written or deleted.
 *   4. Upserts a media_variants row per variant (unique per asset+key: re-runs overwrite, never dup).
 *   5. Copies the configured thumbnail variant's key onto media_assets.thumbnail_ref (the previously
 *      unused column), leaving every other asset field — including visibility — untouched.
 *
 * INVARIANT — originals are preserved: the only write to the original's key would require it to equal a
 * derived key, which cannot happen (derived keys live under the variant_prefix, namespaced by the asset
 * public id and suffixed by variant key + format).
 */
class ImageVariantService
{
    public function __construct(
        private readonly ImageProcessor $processor,
        private readonly VariantPlanner $planner,
    ) {}

    /**
     * Generate (or regenerate) every variant for $asset. Idempotent: re-running overwrites the same
     * objects/rows deterministically. Returns the persisted MediaVariant rows.
     *
     * @return list<MediaVariant>
     *
     * @throws \App\Platform\Media\Exceptions\ImageRejectedException  permanent input rejection (mime/bomb/oversize).
     * @throws ImageProcessingException  the original is missing, or a GD decode/encode failure.
     */
    public function generate(MediaAsset $asset, ?string $surface = null): array
    {
        $storageKey = $asset->storage_key;

        if ($storageKey === null || $storageKey === '') {
            throw new ImageProcessingException('The asset has no stored original to derive variants from.');
        }

        $disk = $this->disk();

        if (! $disk->exists($storageKey)) {
            throw new ImageProcessingException('The original object is missing from storage.', [
                'storage_key' => $storageKey,
            ]);
        }

        $original = (string) $disk->get($storageKey);

        // Guard ONCE up-front from the header, before any full decode (defence in depth: ImageProcessor
        // also guards per render). A rejection here aborts the whole run without deriving anything.
        $this->processor->assertWithinLimits($original);

        $specs = $this->planner->for($asset, $surface);

        $created = [];

        foreach ($specs as $spec) {
            // AVIF is emitted only where the runtime's GD supports it; otherwise the twin is skipped.
            if ($spec->format === 'avif' && ! $this->processor->supportsAvif()) {
                continue;
            }

            $processed = $this->processor->render($original, $spec);
            $variantKey = $this->variantStorageKey($asset, $spec, $processed);

            // A NEW object; the original at $storageKey is never touched.
            $disk->put($variantKey, $processed->bytes);

            $created[] = $this->persist($asset, $spec, $processed, $variantKey);
        }

        $this->applyThumbnailRef($asset, $created);

        return $created;
    }

    /** Upsert the media_variants row for one variant (unique per asset + variant_key). */
    private function persist(MediaAsset $asset, VariantSpec $spec, ProcessedVariant $processed, string $storageKey): MediaVariant
    {
        /** @var MediaVariant $variant */
        $variant = MediaVariant::query()->firstOrNew([
            'media_asset_id' => $asset->getKey(),
            'variant_key' => $spec->key,
        ]);

        $variant->forceFill([
            'media_asset_id' => $asset->getKey(),
            'variant_key' => $spec->key,
            'width' => $processed->width,
            'height' => $processed->height,
            'format' => $processed->format,
            'storage_key' => $storageKey,
            'size_bytes' => $processed->sizeBytes(),
        ])->save();

        return $variant;
    }

    /**
     * Copy the configured thumbnail variant's storage key onto the asset's (previously unused)
     * thumbnail_ref. Only this one column is written — visibility and every other field are left as-is.
     *
     * @param  list<MediaVariant>  $variants
     */
    private function applyThumbnailRef(MediaAsset $asset, array $variants): void
    {
        $thumbnailKey = (string) config('media.images.thumbnail_key', 'thumbnail');

        foreach ($variants as $variant) {
            if ($variant->variant_key === $thumbnailKey) {
                $asset->forceFill(['thumbnail_ref' => $variant->storage_key])->save();

                return;
            }
        }
    }

    /**
     * Deterministic per-asset key for a derived object, namespaced under the variant prefix by the
     * asset public id so it can NEVER collide with the original's key. Same asset + spec => same key
     * (a regeneration overwrites in place rather than orphaning objects).
     */
    private function variantStorageKey(MediaAsset $asset, VariantSpec $spec, ProcessedVariant $processed): string
    {
        $prefix = trim((string) config('media.images.variant_prefix', 'media/variants'), '/');
        $ext = $this->extensionFor($processed->format);

        return sprintf('%s/%s/%s.%s', $prefix, $asset->public_id, $spec->key, $ext);
    }

    private function extensionFor(string $format): string
    {
        return match ($format) {
            'jpeg', 'jpg' => 'jpg',
            default => $format,
        };
    }

    private function disk(): Filesystem
    {
        $name = (string) config('media.images.disk', config('media.s3.disk', 's3'));

        return Storage::disk($name);
    }
}
