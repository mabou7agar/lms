<?php

namespace App\Platform\Media\Jobs;

use App\Platform\Media\Events\MediaVariantsGenerated;
use App\Platform\Media\Exceptions\ImageRejectedException;
use App\Platform\Media\Imaging\ImageVariantService;
use App\Platform\Media\Models\MediaAsset;
use App\Platform\Shared\Audit\AuditLogger;
use App\Platform\Shared\Media\Enums\MediaStatus;
use App\Platform\Shared\Media\Enums\MediaType;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Phase A / D6 - Generates image variants OFF the request path, after an image asset reaches Ready.
 * Dispatched by GenerateImageVariantsOnReady (hooked to the existing MediaReady lifecycle event) and,
 * on demand, by the DAM "Regenerate variants" admin action. The expensive GD work never runs inline
 * with ingestion.
 *
 * Failure model, mirroring the platform's existing job conventions:
 *   - A PERMANENT input rejection (ImageRejectedException: bad mime / decompression-bomb / oversize) is
 *     deterministic, so retrying is pointless. It is caught, audited as `media.variants_rejected`, and
 *     the job returns cleanly — no retry churn.
 *   - A TRANSIENT failure (missing original, GD decode/encode error) is left to propagate so the queue
 *     retries it with backoff up to tries(); the final failure lands in failed() and is dead-lettered
 *     to the audit trail as `media.variants_failed`.
 *
 * afterCommit: MediaReady is dispatched INSIDE the ingestion finalize transaction, so the job is held
 * until that transaction commits — it can never run against an asset row that has not yet been written.
 */
class GenerateImageVariantsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly int $mediaAssetId,
        public readonly ?string $surface = null,
    ) {
        // Set the inherited Queueable::$afterCommit rather than redeclaring the property (redeclaring
        // it with a typed default collides with the trait's untyped property and fatals at composition).
        // MediaReady is dispatched inside the ingestion finalize transaction, so the job must wait for
        // that commit before it can load the asset row.
        $this->afterCommit = true;
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return (array) config('media.images.retry.backoff_seconds', [10, 30, 120]);
    }

    public function tries(): int
    {
        return (int) config('media.images.retry.max_attempts', 3);
    }

    public function handle(ImageVariantService $service, AuditLogger $audit): void
    {
        $asset = MediaAsset::query()->find($this->mediaAssetId);

        // Idempotent no-ops: the asset was deleted, is not an image, or is not (yet) Ready. A non-Ready
        // asset simply means this fired early/out of order; the on-Ready hook will dispatch again.
        if ($asset === null || $asset->type !== MediaType::Image || $asset->status !== MediaStatus::Ready) {
            return;
        }

        try {
            $variants = $service->generate($asset, $this->surface);
        } catch (ImageRejectedException $e) {
            // Deterministic, permanent — audit and stop (no retry).
            $audit->log('media.variants_rejected', $asset, [
                'reason' => $e->getMessage(),
                'details' => $e->details(),
                'surface' => $this->surface,
            ], null);

            return;
        }

        $keys = array_map(static fn ($variant): string => (string) $variant->variant_key, $variants);

        $audit->log('media.variants_generated', $asset, [
            'count' => count($variants),
            'keys' => $keys,
            'surface' => $this->surface,
        ], null);

        MediaVariantsGenerated::dispatch((string) $asset->public_id, $keys);
    }

    /**
     * A job that reaches failed() has genuinely exhausted its retries on a transient error — record the
     * dead-letter on the audit trail so a stuck image is visible to operators.
     */
    public function failed(Throwable $e): void
    {
        $asset = MediaAsset::query()->find($this->mediaAssetId);

        app(AuditLogger::class)->log('media.variants_failed', $asset, [
            'media_asset_id' => $this->mediaAssetId,
            'surface' => $this->surface,
            'error' => substr($e->getMessage(), 0, 500),
        ], null);
    }
}
